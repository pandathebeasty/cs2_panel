<?php

declare(strict_types=1);

namespace App\Core;

/**
 * GitHub-tag based auto-updater.
 *
 * Checks the repo's tags for a newer version than the local VERSION file. The
 * check is cached to a file so we don't hit the GitHub API on every page load.
 * The actual update downloads the tag's source zipball, extracts it, backs up
 * the current files + database, overlays the new files, runs any pending DB
 * migrations, and bumps the VERSION file.
 *
 * Designed to work on shared hosting: pure PHP (cURL + ZipArchive), no shell.
 */
final class Updater
{
    /** How long a "latest version" check is cached (seconds). */
    private const CACHE_TTL = 3600 * 6; // 6 hours

    /** Set true once an update() run finishes cleanly, so the fatal-error
     *  shutdown handler knows a crash did NOT happen. */
    private static bool $completed = false;

    /** Cache file for the last GitHub check. */
    private static function cacheFile(): string
    {
        return BASE_PATH . '/.update_cache.json';
    }

    /** Directory used for downloads/extraction during an update. */
    private static function workDir(): string
    {
        return BASE_PATH . '/.update_tmp';
    }

                                                                            
    // Checking
                                                                            

    /**
     * The latest release info, from cache when fresh, otherwise from GitHub.
     * @param bool $force ignore the cache and re-check now
     * @return array{version:string, codename:string, tag:string, zipball:string, url:string, checked_at:int}|null
     */
    public static function latest(bool $force = false): ?array
    {
        if (!$force) {
            $cached = self::readCache();
            if ($cached !== null && (time() - ($cached['checked_at'] ?? 0)) < self::CACHE_TTL) {
                return $cached['latest'] ?? null;
            }
        }

        $latest = self::fetchLatestFromGitHub();
        // Cache the result (even a null, to avoid hammering the API when offline).
        self::writeCache(['checked_at' => time(), 'latest' => $latest]);
        return $latest;
    }

    /** True when a newer version than the local one is available. */
    public static function hasUpdate(bool $force = false): bool
    {
        $latest = self::latest($force);
        if ($latest === null) {
            return false;
        }
        return Version::compare($latest['version'], Version::current()) > 0;
    }

    /**
     * Non-blocking "is an update available?" for use on every page (the sidebar
     * dot). Reads ONLY the cache — never contacts GitHub — so a normal page load
     * never waits on the network. Returns false when there's no fresh cache yet
     * (the real check runs on the Updates page / the manual check button).
     */
    public static function cachedHasUpdate(): bool
    {
        $latest = self::latestCached();
        if ($latest === null) {
            return false;
        }
        return Version::compare($latest['version'], Version::current()) > 0;
    }

    /** The last cached "latest release" info, or null. Never hits the network. */
    public static function latestCached(): ?array
    {
        $cached = self::readCache();
        return $cached['latest'] ?? null;
    }

    /**
     * Query the GitHub tags API and return the highest semver tag.
     * @return array{version:string, codename:string, tag:string, zipball:string, url:string}|null
     */
    private static function fetchLatestFromGitHub(): ?array
    {
        $api = 'https://api.github.com/repos/' . Version::REPO . '/tags?per_page=100';
        $json = self::httpGet($api);
        if ($json === null) {
            return null;
        }
        $tags = json_decode($json, true);
        if (!is_array($tags) || !$tags) {
            return null;
        }

        $best = null;
        foreach ($tags as $tag) {
            $name = (string) ($tag['name'] ?? '');
            if ($name === '' || !preg_match('/^v?\d+(\.\d+)*/', $name)) {
                continue;
            }
            $ver = Version::normalize($name);
            if ($best === null || version_compare($ver, $best['version'], '>')) {
                $best = [
                    'version'  => $ver,
                    'codename' => Version::codenameFor($ver),
                    'tag'      => $name,
                    'zipball'  => (string) ($tag['zipball_url'] ?? ''),
                    'url'      => 'https://github.com/' . Version::REPO . '/releases/tag/' . rawurlencode($name),
                    'release_name'  => '',
                    'release_notes' => '',
                ];
            }
        }

        // Enrich with the GitHub Release notes (title + body) for that tag, if a
        // published release exists for it. Best-effort — tags without a release
        // simply keep empty notes.
        if ($best !== null) {
            $rel = self::fetchReleaseNotes($best['tag']);
            if ($rel !== null) {
                $best['release_name']  = $rel['name'];
                $best['release_notes'] = $rel['body'];
                $best['assets']        = $rel['assets'];
            }
        }
        return $best;
    }

    /**
     * Fetch a release's title + body for a given tag.
     * @return array{name:string, body:string}|null
     */
    private static function fetchReleaseNotes(string $tag): ?array
    {
        $api = 'https://api.github.com/repos/' . Version::REPO . '/releases/tags/' . rawurlencode($tag);
        $json = self::httpGet($api);
        if ($json === null) {
            return null;
        }
        $rel = json_decode($json, true);
        if (!is_array($rel)) {
            return null;
        }
        $assets = [];
        foreach ((array) ($rel['assets'] ?? []) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            foreach (self::RELEASE_ASSETS as $part => $file) {
                if ($name === $file && !empty($asset['browser_download_url'])) {
                    $assets[$part] = [
                        'url'  => (string) $asset['browser_download_url'],
                        'size' => (int) ($asset['size'] ?? 0),
                    ];
                }
            }
        }
        return [
            'name'   => trim((string) ($rel['name'] ?? '')),
            'body'   => trim((string) ($rel['body'] ?? '')),
            'assets' => $assets,
        ];
    }

    /**
     * Split release archives published by .github/workflows/release-assets.yml.
     * When "core" exists, only core + the selected optional parts are downloaded;
     * otherwise the updater falls back to the full GitHub zipball.
     */
    public const RELEASE_ASSETS = [
        'core'  => 'cs2-panel-core.zip',
        'maps'  => 'cs2-panel-maps.zip',
        'skins' => 'cs2-panel-skins.zip',
        'models' => 'cs2-panel-models.zip',
    ];

                                                                            
    // Updating
                                                                            

    /**
     * Perform the update to the latest tag.
     * @return array{ok:bool, message:string, from?:string, to?:string, log?:string[]}
     */
    public static function update(): array
    {
        $log = [];
        $from = Version::current();

        // Updating is I/O-heavy (download + unzip + full code/DB backup + copy
        // of the whole app/ tree, now including the WeaponPaints data catalog).
        // On shared hosting the default 30s / low memory caps can abort it with
        // a blank 500 mid-way. Lift them for the duration of this request.
        @set_time_limit(0);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', '512M');
        }
        @ignore_user_abort(true);

        // A shutdown handler reports fatal update failures missed by try/catch.
        // It redirects only when no output has been sent.
        self::$completed = false;
        register_shutdown_function(static function (): void {
            if (self::$completed) {
                return; // normal finish — nothing to report
            }
            $err = error_get_last();
            $fatal = $err !== null && in_array($err['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, 1], true);
            $reason = $fatal
                ? ($err['message'] . ' in ' . basename((string) ($err['file'] ?? '')) . ':' . ($err['line'] ?? '?'))
                : 'The update stopped unexpectedly (possible timeout or memory limit on the host).';
            self::logError('Update aborted: ' . $reason);
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Update failed: ' . $reason . ' See db_backups/update_error.log.'];
                $_SESSION['update_log'] = ['Aborted: ' . $reason];
            }
            if (!headers_sent()) {
                $base = defined('BASE_URL') ? (string) BASE_URL : '';
                header('Location: ' . $base . '/settings#updates');
            }
        });

        // Pre-flight guards below return cleanly (no heavy work, no crash risk),
        // so mark completed up-front; it is set back to false only once we enter
        // the heavy section, and back to true at the very end.
        self::$completed = true;

        if (!extension_loaded('zip')) {
            return ['ok' => false, 'message' => 'The PHP "zip" extension is required for auto-update.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'The PHP "cURL" extension is required for auto-update.'];
        }

        $latest = self::latest(true);
        if ($latest === null) {
            return ['ok' => false, 'message' => 'Could not reach GitHub to find the latest release.'];
        }
        if (Version::compare($latest['version'], $from) <= 0) {
            return ['ok' => false, 'message' => 'Already up to date (v' . $from . ').'];
        }
        if (($latest['zipball'] ?? '') === '') {
            return ['ok' => false, 'message' => 'The release has no downloadable archive.'];
        }

        // Entering the heavy, crash-prone section (download/unzip/backup/copy).
        // If a fatal happens past here, the shutdown handler reports it.
        self::$completed = false;

        $work = self::workDir();
        self::rrmdir($work);
        if (!@mkdir($work, 0755, true) && !is_dir($work)) {
            return ['ok' => false, 'message' => 'Cannot create the temp folder: ' . $work];
        }

        // 1) Download the zipball.
        $zipPath = $work . '/release.zip';
        if (!self::download($latest['zipball'], $zipPath)) {
            self::rrmdir($work);
            return ['ok' => false, 'message' => 'Failed to download the release archive.'];
        }
        $log[] = 'Downloaded ' . $latest['tag'] . '.';

        // 2) Extract it.
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            self::rrmdir($work);
            return ['ok' => false, 'message' => 'Failed to open the downloaded archive.'];
        }
        $extractDir = $work . '/extracted';
        @mkdir($extractDir, 0755, true);
        $zip->extractTo($extractDir);
        $zip->close();

        // GitHub zipballs extract into a single top-level folder.
        $root = self::firstSubdir($extractDir);
        if ($root === null) {
            self::rrmdir($work);
            return ['ok' => false, 'message' => 'The archive layout was not recognized.'];
        }
        $log[] = 'Extracted the archive.';

        // 3) Back up the current install (DB + a copy of app/).
        $backup = Backup::full();
        if ($backup) {
            $log[] = 'Database backup: db_backups/' . basename($backup);
        }
        $codeBackup = self::backupCode();
        if ($codeBackup) {
            $log[] = 'Code backup: ' . basename($codeBackup);
        }

        // 4) Overlay the new files onto the install.
        $applied = self::applyFiles($root, $log);
        if (!$applied['ok']) {
            self::rrmdir($work);
            self::$completed = true; // clean, handled failure (not a crash)
            return ['ok' => false, 'message' => $applied['message'], 'log' => $log];
        }

        // 5) Run pending DB migrations shipped with the new version.
        try {
            $ran = Migrator::runPending($root . '/database/migrations');
            foreach ($ran as $m) {
                $log[] = 'Migration: ' . $m;
            }
        } catch (\Throwable $e) {
            $log[] = 'Migration warning: ' . $e->getMessage();
        }

        // 6) Bump the VERSION file to the new version (plain, no codename).
        @file_put_contents(BASE_PATH . '/VERSION', $latest['version'] . "\n");
        $log[] = 'Updated VERSION to ' . $latest['version'] . '.';

        // 7) Clean up temp + refresh the update cache.
        self::rrmdir($work);
        self::latest(true);

        Logger::log('panel.update', null, 'v' . $from . ' -> v' . $latest['version']);

        self::$completed = true; // finished cleanly — disarm the fatal handler

        return [
            'ok'      => true,
            'message' => 'Updated from v' . $from . ' to v' . $latest['version'] . '.',
            'from'    => $from,
            'to'      => $latest['version'],
            'log'     => $log,
        ];
    }

    /**
     * Append a line to the dedicated update error log (db_backups/update_error.log).
     * This is where fatal/aborted updates leave a readable reason even when the
     * host's own PHP error log is empty or disabled.
     */
    public static function logError(string $message): void
    {
        $dir = BASE_PATH . '/db_backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        @file_put_contents($dir . '/update_error.log', $line, FILE_APPEND);
    }

                                                                            
    // Step-based update (for the progress HUD)
                                                                            

    /**
     * Ordered steps with their "progress reached when this step FINISHES" and a
     * label key (resolved to text by the controller/UI). Splitting the update
     * into short requests keeps each one well under the host's time limit (so no
     * blank 500) and lets the UI show a real percentage.
     * @var array<int, array{id:string, percent:int, label:string}>
     */
    private const STEPS = [
        ['id' => 'prepare',     'percent' => 5,   'label' => 'update.step_prepare'],
        ['id' => 'download',    'percent' => 25,  'label' => 'update.step_download'],
        ['id' => 'extract',     'percent' => 40,  'label' => 'update.step_extract'],
        ['id' => 'backup_db',   'percent' => 55,  'label' => 'update.step_backup_db'],
        ['id' => 'backup_code', 'percent' => 70,  'label' => 'update.step_backup_code'],
        ['id' => 'apply',       'percent' => 88,  'label' => 'update.step_apply'],
        ['id' => 'migrate',     'percent' => 95,  'label' => 'update.step_migrate'],
        ['id' => 'finalize',    'percent' => 100, 'label' => 'update.step_finalize'],
    ];

    /** The ordered list of step ids (for the UI / verification). @return string[] */
    public static function stepIds(): array
    {
        return array_map(static fn($s) => $s['id'], self::STEPS);
    }

    /** Metadata (percent + next id) for a step id, or null if unknown. */
    private static function stepMeta(string $id): ?array
    {
        foreach (self::STEPS as $i => $s) {
            if ($s['id'] === $id) {
                $next = self::STEPS[$i + 1]['id'] ?? null;
                return ['percent' => $s['percent'], 'label' => $s['label'], 'next' => $next];
            }
        }
        return null;
    }

    private static function stateFile(): string
    {
        return self::workDir() . '/state.json';
    }

    private static function readState(): array
    {
        $f = self::stateFile();
        if (!is_file($f)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    private static function writeState(array $s): void
    {
        self::writeJsonAtomic(self::stateFile(), $s);
    }

    /** Write JSON via temp file + rename so a concurrent poll never reads half a file. */
    private static function writeJsonAtomic(string $file, array $data): void
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data)) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($file);
            @rename($tmp, $file);
        }
    }

    // Background steps. The release archive is large, so download/extract/backup/
    // copy can outlast a reverse proxy's timeout (which then answers with its own
    // HTML 504/524 page). These steps run as a job: the request that starts one
    // may return early, and the UI polls the same step until the job's stored
    // result is available.

    private const BACKGROUND_STEPS = ['download', 'extract', 'backup_db', 'backup_code', 'apply', 'migrate'];

    /** Seconds without a heartbeat after which a job is considered dead. */
    private const JOB_STALE_AFTER = 300;

    /** Steps that may stay silent longer (a large database dump has no heartbeat). */
    private const JOB_STALE_SLOW = ['backup_db' => 1800, 'backup_code' => 900, 'migrate' => 900];

    /** A dead job is restarted this many times (chunked steps resume from their cursor). */
    private const JOB_MAX_RETRIES = 3;

    /** Work budget per request for chunked steps, well under common host limits. */
    private const CHUNK_SECONDS = 15;

    private static ?string $runningJob = null;

    public static function isBackgroundStep(string $id): bool
    {
        return in_array($id, self::BACKGROUND_STEPS, true);
    }

    private static function jobFile(string $id): string
    {
        return self::workDir() . '/job_' . $id . '.json';
    }

    private static function readJob(string $id): ?array
    {
        $f = self::jobFile($id);
        if (!is_file($f)) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    private static function writeJob(string $id, array $job): void
    {
        if (is_dir(self::workDir())) {
            self::writeJsonAtomic(self::jobFile($id), $job);
        }
    }

    /**
     * Result for a step that was already started: the stored result when the
     * job finished, a "pending" answer while it runs, or a failure when it died
     * silently. Null when the step has not been started yet.
     */
    public static function jobStatus(string $id): ?array
    {
        $meta = self::stepMeta($id);
        $job = self::readJob($id);
        if ($meta === null || $job === null) {
            return null;
        }
        if (($job['status'] ?? '') === 'resume') {
            // The previous chunk stopped on its time budget: run the next one.
            @unlink(self::jobFile($id));
            return null;
        }
        if (($job['status'] ?? '') === 'done' && is_array($job['result'] ?? null)) {
            return $job['result'];
        }
        $lastBeat = max((int) ($job['started'] ?? 0), (int) ($job['beat'] ?? 0));
        if (time() - $lastBeat > (self::JOB_STALE_SLOW[$id] ?? self::JOB_STALE_AFTER)) {
            $st = self::readState();
            $retries = (int) ($st['retries'][$id] ?? 0);
            if ($retries < self::JOB_MAX_RETRIES) {
                // The host killed the worker (time/memory limit). Start it again;
                // chunked steps continue where they stopped.
                $st['retries'][$id] = $retries + 1;
                self::writeState($st);
                self::logError('Step "' . $id . '" stopped without a result; retry ' . ($retries + 1) . '/' . self::JOB_MAX_RETRIES . '.');
                @unlink(self::jobFile($id));
                return null;
            }
            $msg = 'The "' . $id . '" step stopped without reporting a result (host time or memory limit?).';
            self::logError($msg);
            return self::stepFail($id, $meta, $msg);
        }
        return self::pendingResult($id, (string) ($job['detail'] ?? ''), isset($job['fraction']) ? (float) $job['fraction'] : null);
    }

    /** "Still working" answer: keeps the previous step's percent, polls the same step. */
    public static function pendingResult(string $id, string $detail = '', ?float $fraction = null): array
    {
        $meta = self::stepMeta($id) ?? ['label' => '', 'percent' => 0];
        $percent = 0;
        foreach (self::STEPS as $s) {
            if ($s['id'] === $id) {
                break;
            }
            $percent = $s['percent'];
        }
        if ($fraction !== null) {
            $percent += (int) floor(max(0.0, min(0.99, $fraction)) * ((int) ($meta['percent'] ?? $percent) - $percent));
        }
        return [
            'ok'      => true,
            'pending' => true,
            'done'    => false,
            'step'    => $id,
            'next'    => $id,
            'percent' => $percent,
            'label'   => $meta['label'],
            'detail'  => $detail,
            'log'     => [],
        ];
    }

    public static function startJob(string $id): void
    {
        self::writeJob($id, ['status' => 'running', 'started' => time(), 'detail' => '']);
    }

    /** Run a started job to completion and store its result for later polls. */
    public static function runJob(string $id): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $started = (int) (self::readJob($id)['started'] ?? time());
        self::$runningJob = $id;
        register_shutdown_function(static function () use ($id, $started): void {
            if (self::$runningJob !== $id) {
                return;
            }
            $err = error_get_last();
            $reason = $err !== null
                ? $err['message'] . ' in ' . basename((string) ($err['file'] ?? '')) . ':' . ($err['line'] ?? '?')
                : 'The step stopped unexpectedly (possible time or memory limit on the host).';
            self::logError('Step "' . $id . '" aborted: ' . $reason);
            $st = self::readState();
            $retries = (int) ($st['retries'][$id] ?? 0);
            if ($st !== [] && $retries < self::JOB_MAX_RETRIES) {
                // Chunked steps resume from their cursor; others simply run again.
                $st['retries'][$id] = $retries + 1;
                self::writeState($st);
                self::writeJob($id, ['status' => 'resume', 'started' => $started, 'beat' => time()]);
                return;
            }
            $meta = self::stepMeta($id) ?? [];
            self::writeJob($id, ['status' => 'done', 'started' => $started, 'result' => self::stepFail($id, $meta, $reason)]);
        });

        $result = self::step($id);

        self::writeJob($id, !empty($result['resume'])
            ? ['status' => 'resume', 'started' => $started, 'beat' => time()]
            : ['status' => 'done', 'started' => $started, 'result' => $result]);
        self::$runningJob = null;
        return $result;
    }

    /** Update the "detail" text shown by polls while a job runs (e.g. MB downloaded). */
    private static function jobDetail(string $id, string $detail, ?float $fraction = null): void
    {
        $job = self::readJob($id);
        if ($job === null || ($job['status'] ?? '') !== 'running') {
            return;
        }
        $job['detail'] = $detail;
        $job['beat'] = time();
        if ($fraction !== null) {
            $job['fraction'] = $fraction;
        }
        self::writeJob($id, $job);
    }

    /** Result of a chunked step that ran out of its time budget. */
    private static function resumeResult(string $id, string $detail, float $fraction): array
    {
        return ['resume' => true] + self::pendingResult($id, $detail, $fraction);
    }

    /**
     * Run ONE update step and report progress. The frontend calls this
     * repeatedly, following `next` until done:true (percent 100) or ok:false.
     *
     * @return array{ok:bool, done:bool, step:string, next:?string, percent:int,
     *               label:string, message?:string, log?:string[]}
     */
    /**
     * Optional heavy parts of a release. Paths are relative to the release root;
     * when a part is not selected its files are not extracted, so they are
     * neither copied nor overwritten on the install.
     */
    public const OPTIONAL_PARTS = [
        'maps'  => ['assets/img/maps', 'public/assets/img/maps'],
        'skins' => ['app/Data/weaponpaints'],
        'models' => ['assets/3d', 'public/assets/3d'],
    ];

    /** Parts shipped only as a release asset (never in the git zipball). */
    private const ASSET_ONLY_PARTS = ['models'];

    /** @param string[] $parts selected optional parts (prepare step only) */
    public static function step(string $id, array $parts = [], bool $reinstall = false): array
    {
        @set_time_limit(0);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', '512M');
        }
        @ignore_user_abort(true);

        $meta = self::stepMeta($id);
        if ($meta === null) {
            return ['ok' => false, 'done' => false, 'step' => $id, 'next' => null, 'percent' => 0, 'label' => '', 'message' => 'Unknown update step: ' . $id];
        }

        if (!extension_loaded('zip') || !function_exists('curl_init')) {
            return self::stepFail($id, $meta, 'The PHP "zip" and "cURL" extensions are required for auto-update.');
        }

        try {
            $log = [];
            switch ($id) {
                case 'prepare':
                    $from = Version::current();
                    $latest = self::latest(true);
                    if ($latest === null) {
                        return self::stepFail($id, $meta, 'Could not reach GitHub to find the latest release.');
                    }
                    // A reinstall re-applies the same version (repairs missing or edited files); never a downgrade.
                    $cmp = Version::compare($latest['version'], $from);
                    if ($cmp < 0 || ($cmp === 0 && !$reinstall)) {
                        return self::stepFail($id, $meta, 'Already up to date (v' . $from . ').');
                    }
                    if (($latest['zipball'] ?? '') === '') {
                        return self::stepFail($id, $meta, 'The release has no downloadable archive.');
                    }
                    $work = self::workDir();
                    self::rrmdir($work);
                    if (!@mkdir($work, 0755, true) && !is_dir($work)) {
                        return self::stepFail($id, $meta, 'Cannot create the temp folder: ' . $work);
                    }
                    $selectedParts = array_values(array_intersect(array_keys(self::OPTIONAL_PARTS), $parts));
                    $downloads = [];
                    $assets = (array) ($latest['assets'] ?? []);
                    if (!empty($assets['core']['url'])) {
                        $downloads['core'] = (string) $assets['core']['url'];
                        foreach ($selectedParts as $part) {
                            if (!empty($assets[$part]['url'])) {
                                $downloads[$part] = (string) $assets[$part]['url'];
                            } elseif (in_array($part, self::ASSET_ONLY_PARTS, true)) {
                                $log[] = 'The release has no ' . self::RELEASE_ASSETS[$part] . '; skipped.';
                            } else {
                                // A selected part without its own archive: use the full zipball.
                                $downloads = [];
                                break;
                            }
                        }
                    }
                    self::writeState([
                        'from'      => $from,
                        'version'   => $latest['version'],
                        'tag'       => $latest['tag'],
                        'zipball'   => $latest['zipball'],
                        'parts'     => $selectedParts,
                        'downloads' => $downloads,
                    ]);
                    $log[] = ($cmp === 0 ? 'Preparing reinstall of v' : 'Preparing update to v') . $latest['version'] . '.';
                    if ($downloads) {
                        $log[] = 'Using split release archives: ' . implode(', ', array_keys($downloads)) . '.';
                    }
                    break;

                case 'download':
                    $st = self::readState();
                    if (empty($st['zipball'])) {
                        return self::stepFail($id, $meta, 'Update state missing; restart the update.');
                    }
                    $targets = (array) ($st['downloads'] ?? []);
                    if (!$targets) {
                        $targets = ['release' => (string) $st['zipball']];
                    }
                    foreach ($targets as $name => $url) {
                        $lastReport = 0.0;
                        $onProgress = static function (int $bytes) use (&$lastReport, $name): void {
                            $now = microtime(true);
                            if ($now - $lastReport >= 1.0) {
                                $lastReport = $now;
                                self::jobDetail('download', $name . ': ' . round($bytes / 1048576, 1) . ' MB');
                            }
                        };
                        $file = self::workDir() . '/' . ($name === 'release' ? 'release' : 'part_' . $name) . '.zip';
                        if (!self::download((string) $url, $file, $onProgress)) {
                            return self::stepFail($id, $meta, 'Failed to download the release archive (' . $name . ').');
                        }
                        $log[] = 'Downloaded ' . $name . ' (' . round(filesize($file) / 1048576, 1) . ' MB).';
                    }
                    break;

                case 'extract':
                    $extractDir = self::workDir() . '/extracted';
                    @mkdir($extractDir, 0755, true);
                    $st = self::readState();
                    $planFile = self::workDir() . '/extract_plan.json';
                    $plan = is_file($planFile) ? json_decode((string) @file_get_contents($planFile), true) : null;
                    if (!is_array($plan)) {
                        $plan = self::extractPlan($st, $log);
                        if (isset($plan['error'])) {
                            return self::stepFail($id, $meta, (string) $plan['error']);
                        }
                        self::writeJsonAtomic($planFile, $plan);
                    }
                    $progress = self::extractChunk($plan, $extractDir);
                    if ($progress['error'] !== null) {
                        return self::stepFail($id, $meta, $progress['error']);
                    }
                    self::writeJsonAtomic($planFile, $plan);
                    if (!$progress['finished']) {
                        return self::resumeResult($id, $progress['detail'], $progress['fraction']);
                    }
                    $log[] = 'Extracted ' . $plan['total'] . ' files.';
                    $root = self::firstSubdir($extractDir);
                    if ($root === null) {
                        return self::stepFail($id, $meta, 'The archive layout was not recognized.');
                    }
                    $st['root'] = $root;
                    self::writeState($st);
                    if (!empty($plan['skipped'])) {
                        $log[] = 'Skipped: ' . $plan['skipped'] . '.';
                    }
                    break;

                case 'backup_db':
                    $backup = Backup::full();
                    if ($backup) {
                        $log[] = 'Database backup: db_backups/' . basename($backup);
                        $st = self::readState();
                        $st['db_backup'] = basename($backup);
                        self::writeState($st);
                    }
                    break;

                case 'backup_code':
                    $codeBackup = self::backupCode();
                    if ($codeBackup) {
                        $log[] = 'Code backup: ' . basename($codeBackup);
                        $st = self::readState();
                        $st['code_backup'] = basename($codeBackup);
                        self::writeState($st);
                    }
                    break;

                case 'apply':
                    $st = self::readState();
                    if (empty($st['root']) || !is_dir($st['root'])) {
                        return self::stepFail($id, $meta, 'Extracted files missing; restart the update.');
                    }
                    $applied = self::applyFiles($st['root'], $log);
                    if (!$applied['ok']) {
                        return self::stepFail($id, $meta, $applied['message'], $log);
                    }
                    if (empty($applied['finished'])) {
                        return self::resumeResult($id, (string) $applied['detail'], (float) $applied['fraction']);
                    }
                    break;

                case 'migrate':
                    $st = self::readState();
                    if (!empty($st['root'])) {
                        try {
                            foreach (Migrator::runPending($st['root'] . '/database/migrations') as $m) {
                                $log[] = 'Migration: ' . $m;
                            }
                        } catch (\Throwable $e) {
                            $log[] = 'Migration warning: ' . $e->getMessage();
                        }
                    }
                    break;

                case 'finalize':
                    $st = self::readState();
                    $ver = (string) ($st['version'] ?? Version::current());
                    $from = (string) ($st['from'] ?? Version::current());
                    @file_put_contents(BASE_PATH . '/VERSION', $ver . "\n");
                    $log[] = 'Updated VERSION to ' . $ver . '.';
                    // A same-version reinstall (e.g. adding 3D models) keeps the rollback to the previous version.
                    $keepRollback = Version::compare($from, $ver) === 0 && self::rollbackInfo() !== null;
                    if (!empty($st['code_backup']) && !$keepRollback) {
                        self::writeJsonAtomic(self::rollbackFile(), [
                            'from'        => $from,
                            'to'          => $ver,
                            'code_backup' => (string) $st['code_backup'],
                            'db_backup'   => (string) ($st['db_backup'] ?? ''),
                            'at'          => time(),
                            'installed_files' => self::rollbackCleanupFiles((string) ($st['root'] ?? '')),
                        ]);
                    }
                    self::rrmdir(self::workDir());
                    self::latest(true);
                    Logger::log('panel.update', null, 'v' . $from . ' -> v' . $ver);
                    $log[] = 'Update complete.';
                    break;
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            self::logError('Step "' . $id . '" failed: ' . $msg);
            return self::stepFail($id, $meta, $msg);
        }

        $done = $meta['next'] === null;
        return [
            'ok'      => true,
            'done'    => $done,
            'step'    => $id,
            'next'    => $meta['next'],
            'percent' => $meta['percent'],
            'label'   => $meta['label'],
            'log'     => $log,
        ];
    }

                                                                               
    private static function stepFail(string $id, array $meta, string $message, array $log = []): array
    {
        return [
            'ok'      => false,
            'done'    => false,
            'step'    => $id,
            'next'    => null,
            'percent' => $meta['percent'] ?? 0,
            'label'   => $meta['label'] ?? '',
            'message' => $message,
            'log'     => $log,
        ];
    }

    /**
     * Copy the release files over the current install in time-boxed chunks;
     * the cursor is kept in apply_plan.json so the next request resumes.
     * @return array{ok:bool, message:string, finished?:bool, detail?:string, fraction?:float}
     */
    private static function applyFiles(string $root, array &$log): array
    {
        $planFile = self::workDir() . '/apply_plan.json';
        $plan = is_file($planFile) ? json_decode((string) @file_get_contents($planFile), true) : null;
        if (!is_array($plan)) {
            $plan = ['files' => self::applyList($root), 'i' => 0];
            self::writeJsonAtomic($planFile, $plan);
        }
        $files = (array) $plan['files'];
        $total = count($files);
        $i = (int) $plan['i'];
        $deadline = microtime(true) + self::CHUNK_SECONDS;
        $failed = null;
        for (; $i < $total; $i++) {
            [$src, $dest] = $files[$i];
            $dir = dirname($dest);
            if ((!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) || !@copy($src, $dest)) {
                $failed = $dest;
                break;
            }
            if ($i % 50 === 0) {
                self::jobDetail('apply', ($i + 1) . ' / ' . $total, $total ? $i / $total : 0.0);
                if (microtime(true) >= $deadline) {
                    $i++;
                    break;
                }
            }
        }
        $plan['i'] = $i;
        self::writeJsonAtomic($planFile, $plan);
        if ($failed !== null) {
            $rel = ltrim(str_replace('\\', '/', substr($failed, strlen(BASE_PATH))), '/');
            return ['ok' => false, 'message' => 'Failed while copying ' . $rel . ' (check folder permissions).'];
        }
        if ($i < $total) {
            return ['ok' => true, 'finished' => false, 'message' => 'ok', 'detail' => $i . ' / ' . $total, 'fraction' => $i / $total];
        }

        // Only VERSION is worth syncing from the top level. README.md and other
        // repo/dev files are intentionally NOT copied onto the live install (the
        // GitHub zipball always ships a README, which otherwise lands in the
        // panel root on every update). Never touch config.
        if (is_file($root . '/VERSION')) {
            @copy($root . '/VERSION', BASE_PATH . '/VERSION');
        }
        $log[] = 'Applied ' . $total . ' files.';
        return ['ok' => true, 'finished' => true, 'message' => 'ok'];
    }

    /**
     * Files to copy over the install, as [source, destination] pairs.
     * We only overlay code/asset folders and never touch config/config.php,
     * db_backups/, or the user's uploaded logo/favicon.
     * @return array<int,array{0:string,1:string}>
     */
    private static function applyList(string $root): array
    {
        // Assets live under public/assets in layout A, or /assets in layout B
        // (the flat deploy). Detect which one this install uses.
        $assetsDest = is_dir(PUBLIC_PATH . '/assets') ? PUBLIC_PATH . '/assets' : BASE_PATH . '/assets';
        $copies = [
            $root . '/app'             => BASE_PATH . '/app',
            $root . '/database'        => BASE_PATH . '/database',
            $root . '/public/assets'   => $assetsDest,
            // Flat repo layout ships assets/ at the release root.
            $root . '/assets'          => is_dir($root . '/public/assets') ? null : $assetsDest,
        ];
        $list = [];
        foreach ($copies as $srcDir => $destDir) {
            if ($destDir === null || !is_dir($srcDir)) {
                continue; // release may not ship every folder
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if ($f->isFile()) {
                    $list[] = [$f->getPathname(), $destDir . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($srcDir) + 1))];
                }
            }
        }
        return $list;
    }

    /**
     * Archives and entry indexes to extract, honouring the unselected optional parts.
     * @return array{archives:array<int,array{file:string,entries:int[]}>, a:int, i:int, done:int, total:int, skipped:string}|array{error:string}
     */
    private static function extractPlan(array $st, array &$log): array
    {
        $split = glob(self::workDir() . '/part_*.zip') ?: [];
        $archives = $split ?: [self::workDir() . '/release.zip'];
        $skip = $split ? [] : self::skippedPrefixes((array) ($st['parts'] ?? array_keys(self::OPTIONAL_PARTS)));
        $plan = ['archives' => [], 'a' => 0, 'i' => 0, 'done' => 0, 'total' => 0, 'skipped' => ''];
        foreach ($archives as $archive) {
            $zip = new \ZipArchive();
            if ($zip->open($archive) !== true) {
                return ['error' => 'Failed to open ' . basename($archive) . '.'];
            }
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                // Drop the zipball's top-level folder before matching.
                $rel = (string) substr($name, (int) strpos($name, '/') + 1);
                if ($skip === [] || !self::isSkipped($rel, $skip)) {
                    $entries[] = $i;
                }
            }
            $zip->close();
            $plan['archives'][] = ['file' => $archive, 'entries' => $entries];
            $plan['total'] += count($entries);
        }
        if ($skip !== []) {
            $plan['skipped'] = implode(', ', array_diff(array_keys(self::OPTIONAL_PARTS), (array) ($st['parts'] ?? [])));
        }
        return $plan;
    }

    /**
     * Extract entries from the plan until the time budget runs out. The plan's
     * cursor is advanced in place.
     * @return array{finished:bool, error:?string, detail:string, fraction:float}
     */
    private static function extractChunk(array &$plan, string $extractDir): array
    {
        $deadline = microtime(true) + self::CHUNK_SECONDS;
        $total = max(1, (int) $plan['total']);
        while ($plan['a'] < count($plan['archives'])) {
            $archive = $plan['archives'][$plan['a']];
            $zip = new \ZipArchive();
            if ($zip->open($archive['file']) !== true) {
                return ['finished' => false, 'error' => 'Failed to open ' . basename($archive['file']) . '.', 'detail' => '', 'fraction' => 0.0];
            }
            $entries = $archive['entries'];
            $count = count($entries);
            while ($plan['i'] < $count) {
                $batch = [];
                for ($n = 0; $n < 100 && $plan['i'] < $count; $n++, $plan['i']++) {
                    $batch[] = (string) $zip->getNameIndex((int) $entries[$plan['i']]);
                }
                if (!$zip->extractTo($extractDir, $batch)) {
                    $zip->close();
                    return ['finished' => false, 'error' => 'Failed to extract ' . basename($archive['file']) . ' (disk space or permissions?).', 'detail' => '', 'fraction' => 0.0];
                }
                $plan['done'] += count($batch);
                self::jobDetail('extract', $plan['done'] . ' / ' . $plan['total'], $plan['done'] / $total);
                if (microtime(true) >= $deadline && ($plan['i'] < $count || $plan['a'] + 1 < count($plan['archives']))) {
                    $zip->close();
                    if ($plan['i'] >= $count) {
                        $plan['a']++;
                        $plan['i'] = 0;
                    }
                    return ['finished' => false, 'error' => null, 'detail' => $plan['done'] . ' / ' . $plan['total'], 'fraction' => $plan['done'] / $total];
                }
            }
            $zip->close();
            $plan['a']++;
            $plan['i'] = 0;
        }
        return ['finished' => true, 'error' => null, 'detail' => '', 'fraction' => 1.0];
    }

    /**
     * Zip the code that an update overwrites (app/, database/, assets/ and
     * VERSION) so it can be rolled back, including user uploads. The heavy data
     * folders (map images, skin catalog, 3D models) are left out.
     */
    private static function backupCode(): ?string
    {
        if (!extension_loaded('zip')) {
            return null;
        }
        $dir = BASE_PATH . '/db_backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $file = $dir . '/code_' . Version::current() . '_' . date('Y-m-d_H-i-s') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $exclude = ['app/Data/weaponpaints', 'assets/img/maps', 'assets/3d'];
        foreach (self::codeRoots() as $prefix => $source) {
            if (!is_dir($source)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!$f->isFile()) {
                    continue;
                }
                $local = $prefix . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($source) + 1));
                if (!self::isSkipped($local, $exclude)) {
                    $zip->addFile($f->getPathname(), $local);
                }
            }
        }
        if (is_file(BASE_PATH . '/VERSION')) {
            $zip->addFile(BASE_PATH . '/VERSION', 'VERSION');
        }
        $zip->close();
        return $file;
    }

    /** @return array<string,string> backup prefix => install directory */
    private static function codeRoots(): array
    {
        return [
            'app'      => BASE_PATH . '/app',
            'database' => BASE_PATH . '/database',
            'assets'   => is_dir(PUBLIC_PATH . '/assets') ? PUBLIC_PATH . '/assets' : BASE_PATH . '/assets',
        ];
    }

    private static function rollbackFile(): string
    {
        return BASE_PATH . '/db_backups/last_update.json';
    }

    private static function isRollbackCodeFile(string $name): bool
    {
        return !str_contains($name, '\\') && !str_contains($name, "\0") && !str_contains($name, '..')
            && preg_match('~\A(?:app/(?!Data/).+\.php|database/.+\.sql)\z~', $name) === 1;
    }

    /** @return array<string,string> release-relative code path => SHA-256 */
    private static function rollbackCleanupFiles(string $root): array
    {
        if ($root === '' || !is_dir($root)) {
            throw new \RuntimeException('Extracted files missing; cannot record rollback files.');
        }
        $files = [];
        $prefixLength = strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1;
        foreach (self::applyList($root) as [$source]) {
            $name = substr(str_replace('\\', '/', $source), $prefixLength);
            if (!self::isRollbackCodeFile($name)) {
                continue;
            }
            $hash = @hash_file('sha256', $source);
            if ($hash === false) {
                throw new \RuntimeException('Cannot read ' . $name . ' for the rollback file manifest.');
            }
            $files[$name] = $hash;
        }
        return $files;
    }

    /**
     * The last completed update that can be rolled back, when its code backup
     * still exists and the panel still runs the version it installed.
     * @return array{from:string,to:string,code_backup:string,db_backup:string,at:int,has_db:bool,installed_files:?array<string,string>}|null
     */
    public static function rollbackInfo(): ?array
    {
        $file = self::rollbackFile();
        if (!is_file($file)) {
            return null;
        }
        $info = json_decode((string) @file_get_contents($file), true);
        if (!is_array($info) || empty($info['code_backup'])) {
            return null;
        }
        if (Version::compare((string) ($info['from'] ?? ''), (string) ($info['to'] ?? '')) === 0) {
            $info = self::olderCodeBackup((string) ($info['to'] ?? ''));
            if ($info === null) {
                return null;
            }
        }
        $code = BASE_PATH . '/db_backups/' . basename((string) $info['code_backup']);
        if (!is_file($code) || Version::compare(Version::current(), (string) ($info['to'] ?? '')) !== 0) {
            return null;
        }
        $db = (string) ($info['db_backup'] ?? '');
        return [
            'from'        => (string) ($info['from'] ?? ''),
            'to'          => (string) ($info['to'] ?? ''),
            'code_backup' => basename((string) $info['code_backup']),
            'db_backup'   => $db !== '' ? basename($db) : '',
            'at'          => (int) ($info['at'] ?? 0),
            'has_db'      => $db !== '' && is_file(BASE_PATH . '/db_backups/' . basename($db)),
            'installed_files' => is_array($info['installed_files'] ?? null) ? $info['installed_files'] : null,
        ];
    }

    /**
     * Rollback record rebuilt from the newest code backup of an older version,
     * used when a same-version reinstall overwrote the real rollback record.
     * The database backup taken in the same update (up to 1h earlier) is paired.
     * @return array{from:string,to:string,code_backup:string,db_backup:string,at:int}|null
     */
    private static function olderCodeBackup(string $to): ?array
    {
        $best = null;
        $bestTime = 0;
        $bestVer = '';
        foreach (glob(BASE_PATH . '/db_backups/code_*.zip') ?: [] as $file) {
            if (!preg_match('/^code_(\d+(?:\.\d+)*)_/', basename($file), $m) || Version::compare($m[1], $to) >= 0) {
                continue;
            }
            $time = (int) @filemtime($file);
            if ($time > $bestTime) {
                $best = $file;
                $bestTime = $time;
                $bestVer = $m[1];
            }
        }
        if ($best === null) {
            return null;
        }
        $db = '';
        $dbTime = 0;
        foreach (glob(BASE_PATH . '/db_backups/backup_*.sql*') ?: [] as $file) {
            $time = (int) @filemtime($file);
            if ($time <= $bestTime && $time >= $bestTime - 3600 && $time > $dbTime) {
                $db = basename($file);
                $dbTime = $time;
            }
        }
        return ['from' => $bestVer, 'to' => $to, 'code_backup' => basename($best), 'db_backup' => $db, 'at' => $bestTime];
    }

    /**
     * Restore the code (and optionally the database) saved before the last
     * update. Only unchanged code files shipped by that update and absent
     * from the backup are removed; unknown or customized files are kept.
     * @return array{ok:bool,message:string,log:string[]}
     */
    public static function rollback(bool $restoreDb): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $info = self::rollbackInfo();
        if ($info === null) {
            return ['ok' => false, 'message' => 'No update to roll back.', 'log' => []];
        }
        $log = [];
        foreach ($info['installed_files'] ?? [] as $name => $hash) {
            if (!is_string($name) || !self::isRollbackCodeFile($name) || !is_string($hash)
                || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
                return ['ok' => false, 'message' => 'Invalid rollback file manifest; no files were restored or removed.', 'log' => $log];
            }
        }
        $zip = new \ZipArchive();
        if ($zip->open(BASE_PATH . '/db_backups/' . $info['code_backup']) !== true) {
            return ['ok' => false, 'message' => 'Cannot open the code backup.', 'log' => $log];
        }
        $roots = self::codeRoots();
        $kept = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_ends_with($name, '/') || str_contains($name, '..')) {
                continue;
            }
            $prefix = strstr($name, '/', true);
            if ($name === 'VERSION') {
                $dest = BASE_PATH . '/VERSION';
            } elseif ($prefix !== false && isset($roots[$prefix])) {
                $dest = $roots[$prefix] . substr($name, strlen($prefix));
            } else {
                continue;
            }
            $key = str_replace('\\', '/', $dest);
            $kept[DIRECTORY_SEPARATOR === '\\' ? strtolower($key) : $key] = true;
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                continue;
            }
            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0755, true);
            }
            if (@file_put_contents($dest, $data) === false) {
                $zip->close();
                return ['ok' => false, 'message' => 'Cannot write ' . $name . ' (check permissions).', 'log' => $log];
            }
        }
        $zip->close();
        $log[] = 'Restored the code of v' . $info['from'] . '.';

        $removed = 0;
        if ($info['installed_files'] === null) {
            $log[] = 'No update file manifest is available; files absent from the backup were kept. Newer classes or migrations may need manual review.';
        } else {
            foreach ($info['installed_files'] as $name => $expectedHash) {
                $parts = explode('/', $name);
                $path = $roots[array_shift($parts)];
                $linked = is_link($path);
                foreach ($parts as $part) {
                    $path .= '/' . $part;
                    $linked = $linked || is_link($path);
                }
                $key = str_replace('\\', '/', $path);
                if (isset($kept[DIRECTORY_SEPARATOR === '\\' ? strtolower($key) : $key])) {
                    continue;
                }
                if ($linked) {
                    $log[] = 'Kept linked file: ' . $name . '.';
                    continue;
                }
                if (!is_file($path)) {
                    continue;
                }
                $hash = @hash_file('sha256', $path);
                if ($hash === false) {
                    return ['ok' => false, 'message' => 'Code restored, but cannot read ' . $name . ' for rollback cleanup.', 'log' => $log];
                }
                if (!hash_equals($expectedHash, $hash)) {
                    $log[] = 'Kept customized file: ' . $name . '.';
                    continue;
                }
                if (!@unlink($path)) {
                    return ['ok' => false, 'message' => 'Code restored, but cannot remove ' . $name . ' (check permissions).', 'log' => $log];
                }
                $removed++;
            }
        }
        if ($removed > 0) {
            $log[] = 'Removed ' . $removed . ' file(s) added by v' . $info['to'] . '.';
        }

        if ($restoreDb && $info['has_db']) {
            $restored = Backup::restore(BASE_PATH . '/db_backups/' . $info['db_backup']);
            if (!$restored['ok']) {
                return ['ok' => false, 'message' => 'Code restored, but the database restore failed: ' . $restored['message'], 'log' => $log];
            }
            $log[] = 'Database restored from ' . $info['db_backup'] . ' (' . $restored['statements'] . ' statements).';
        }

        @unlink(self::rollbackFile());
        @unlink(self::cacheFile());
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        Logger::log('panel.rollback', null, 'v' . $info['to'] . ' -> v' . $info['from'] . ($restoreDb ? ' (with database)' : ''));
        return ['ok' => true, 'message' => 'Rolled back to v' . $info['from'] . '.', 'log' => $log];
    }

                                                                            
    // Helpers
                                                                            

    /** HTTP GET with a GitHub-friendly User-Agent. Returns body or null. */
    private static function httpGet(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: CS2Panel-Updater',
                'Accept: application/vnd.github+json',
            ],
        ]);
        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if ($ca && is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }
        return (string) $body;
    }

    /**
     * Download a URL to a file. Returns true on success. No overall time cap
     * (the archive is large); a stalled transfer is aborted instead.
     * @param (callable(int):void)|null $onProgress receives bytes downloaded so far
     */
    private static function download(string $url, string $dest, ?callable $onProgress = null): bool
    {
        $ch = curl_init($url);
        $fh = @fopen($dest, 'w');
        if ($fh === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fh,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_CONNECTTIMEOUT  => 20,
            CURLOPT_TIMEOUT         => 0,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME  => 60,
            CURLOPT_HTTPHEADER      => ['User-Agent: CS2Panel-Updater'],
        ]);
        if ($onProgress !== null) {
            curl_setopt_array($ch, [
                CURLOPT_NOPROGRESS       => false,
                CURLOPT_XFERINFOFUNCTION => static function ($ch, $dlTotal, $dlNow) use ($onProgress): int {
                    $onProgress((int) $dlNow);
                    return 0;
                },
            ]);
        }
        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if ($ca && is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);
        if ($ok === false || $status < 200 || $status >= 300 || !is_file($dest) || filesize($dest) === 0) {
            @unlink($dest);
            return false;
        }
        return true;
    }

    /** Release-relative path prefixes of the optional parts NOT selected. @return string[] */
    private static function skippedPrefixes(array $selected): array
    {
        $skip = [];
        foreach (self::OPTIONAL_PARTS as $part => $paths) {
            if (!in_array($part, $selected, true)) {
                array_push($skip, ...$paths);
            }
        }
        return $skip;
    }

    private static function isSkipped(string $rel, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if ($rel === $p || $rel === $p . '/' || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    /** The first sub-directory inside a folder (GitHub zipball root). */
    private static function firstSubdir(string $dir): ?string
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p)) {
                return $p;
            }
        }
        return null;
    }

    /** Recursively delete a directory. */
    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private static function readCache(): ?array
    {
        $f = self::cacheFile();
        if (!is_file($f)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($f), true);
        return is_array($data) ? $data : null;
    }

    private static function writeCache(array $data): void
    {
        @file_put_contents(self::cacheFile(), json_encode($data));
    }
}

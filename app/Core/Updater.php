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
        return [
            'name' => trim((string) ($rel['name'] ?? '')),
            'body' => trim((string) ($rel['body'] ?? '')),
        ];
    }

                                                                            
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

    /** Seconds after which a job with no stored result is considered dead. */
    private const JOB_STALE_AFTER = 1800;

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
        if (($job['status'] ?? '') === 'done' && is_array($job['result'] ?? null)) {
            return $job['result'];
        }
        if (time() - (int) ($job['started'] ?? 0) > self::JOB_STALE_AFTER) {
            $msg = 'The "' . $id . '" step stopped without reporting a result (host time or memory limit?).';
            self::logError($msg);
            return self::stepFail($id, $meta, $msg);
        }
        return self::pendingResult($id, (string) ($job['detail'] ?? ''));
    }

    /** "Still working" answer: keeps the previous step's percent, polls the same step. */
    public static function pendingResult(string $id, string $detail = ''): array
    {
        $meta = self::stepMeta($id) ?? ['label' => '', 'percent' => 0];
        $percent = 0;
        foreach (self::STEPS as $s) {
            if ($s['id'] === $id) {
                break;
            }
            $percent = $s['percent'];
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
            $meta = self::stepMeta($id) ?? [];
            self::writeJob($id, ['status' => 'done', 'started' => $started, 'result' => self::stepFail($id, $meta, $reason)]);
        });

        $result = self::step($id);

        self::writeJob($id, ['status' => 'done', 'started' => $started, 'result' => $result]);
        self::$runningJob = null;
        return $result;
    }

    /** Update the "detail" text shown by polls while a job runs (e.g. MB downloaded). */
    private static function jobDetail(string $id, string $detail): void
    {
        $job = self::readJob($id);
        if ($job === null || ($job['status'] ?? '') !== 'running') {
            return;
        }
        $job['detail'] = $detail;
        self::writeJob($id, $job);
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
    ];

    /** @param string[] $parts selected optional parts (prepare step only) */
    public static function step(string $id, array $parts = []): array
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
                    if (Version::compare($latest['version'], $from) <= 0) {
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
                    self::writeState([
                        'from'    => $from,
                        'version' => $latest['version'],
                        'tag'     => $latest['tag'],
                        'zipball' => $latest['zipball'],
                        'parts'   => array_values(array_intersect(array_keys(self::OPTIONAL_PARTS), $parts)),
                    ]);
                    $log[] = 'Preparing update to v' . $latest['version'] . '.';
                    break;

                case 'download':
                    $st = self::readState();
                    if (empty($st['zipball'])) {
                        return self::stepFail($id, $meta, 'Update state missing; restart the update.');
                    }
                    $lastReport = 0.0;
                    $onProgress = static function (int $bytes) use (&$lastReport): void {
                        $now = microtime(true);
                        if ($now - $lastReport >= 1.0) {
                            $lastReport = $now;
                            self::jobDetail('download', round($bytes / 1048576, 1) . ' MB');
                        }
                    };
                    if (!self::download($st['zipball'], self::workDir() . '/release.zip', $onProgress)) {
                        return self::stepFail($id, $meta, 'Failed to download the release archive.');
                    }
                    $log[] = 'Downloaded ' . ($st['tag'] ?? '') . '.';
                    break;

                case 'extract':
                    $zip = new \ZipArchive();
                    if ($zip->open(self::workDir() . '/release.zip') !== true) {
                        return self::stepFail($id, $meta, 'Failed to open the downloaded archive.');
                    }
                    $extractDir = self::workDir() . '/extracted';
                    @mkdir($extractDir, 0755, true);
                    $st = self::readState();
                    $skip = self::skippedPrefixes((array) ($st['parts'] ?? array_keys(self::OPTIONAL_PARTS)));
                    if ($skip === []) {
                        $zip->extractTo($extractDir);
                    } else {
                        $names = [];
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $name = (string) $zip->getNameIndex($i);
                            // Drop the zipball's top-level folder before matching.
                            $rel = (string) substr($name, (int) strpos($name, '/') + 1);
                            if (!self::isSkipped($rel, $skip)) {
                                $names[] = $name;
                            }
                        }
                        $zip->extractTo($extractDir, $names);
                        $log[] = 'Skipped: ' . implode(', ', array_diff(array_keys(self::OPTIONAL_PARTS), (array) $st['parts'])) . '.';
                    }
                    $zip->close();
                    $root = self::firstSubdir($extractDir);
                    if ($root === null) {
                        return self::stepFail($id, $meta, 'The archive layout was not recognized.');
                    }
                    $st['root'] = $root;
                    self::writeState($st);
                    $log[] = 'Extracted the archive.';
                    break;

                case 'backup_db':
                    $backup = Backup::full();
                    if ($backup) {
                        $log[] = 'Database backup: db_backups/' . basename($backup);
                    }
                    break;

                case 'backup_code':
                    $codeBackup = self::backupCode();
                    if ($codeBackup) {
                        $log[] = 'Code backup: ' . basename($codeBackup);
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
     * Copy the updatable parts of the release over the current install.
     * We only overlay code/asset folders and never touch config/config.php,
     * db_backups/, or the user's uploaded logo/favicon.
     * @return array{ok:bool, message:string}
     */
    private static function applyFiles(string $root, array &$log): array
    {
        // Map source (in the release) -> destination (in this install).
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

        foreach ($copies as $srcDir => $destDir) {
            if ($destDir === null || !is_dir($srcDir)) {
                continue; // release may not ship every folder
            }
            if (!self::copyTree($srcDir, $destDir)) {
                return ['ok' => false, 'message' => 'Failed while copying ' . basename($destDir) . '/ (check folder permissions).'];
            }
            $log[] = 'Applied ' . basename($destDir) . '/.';
        }

        // Only VERSION is worth syncing from the top level. README.md and other
        // repo/dev files are intentionally NOT copied onto the live install (the
        // GitHub zipball always ships a README, which otherwise lands in the
        // panel root on every update). Never touch config.
        if (is_file($root . '/VERSION')) {
            @copy($root . '/VERSION', BASE_PATH . '/VERSION');
        }
        return ['ok' => true, 'message' => 'ok'];
    }

    /** Make a zip backup of the current app/ folder before overwriting it. */
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
        $appDir = BASE_PATH . '/app';
        $base = dirname($appDir);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile()) {
                $local = ltrim(str_replace($base, '', $f->getPathname()), '/\\');
                $zip->addFile($f->getPathname(), $local);
            }
        }
        $zip->close();
        return $file;
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

    /** Recursively copy a directory tree, creating destinations as needed. */
    private static function copyTree(string $src, string $dest): bool
    {
        if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $target = $dest . '/' . $it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                    return false;
                }
            } else {
                if (!@copy($item->getPathname(), $target)) {
                    return false;
                }
            }
        }
        return true;
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

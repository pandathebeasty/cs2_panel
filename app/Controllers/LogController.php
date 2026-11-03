<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;

final class LogController extends Controller
{
    /** Absolute path to the app error log written by the exception handler. */
    private function errorLogPath(): string
    {
        return BASE_PATH . '/error.log';
    }

    private function filters(): array
    {
        return [
            'search' => trim((string) $this->query('search', '')),
            'action' => (string) $this->query('action', ''),
            'ip'     => trim((string) $this->query('ip', '')),
            'from'   => (string) $this->query('from', ''),
            'to'     => (string) $this->query('to', ''),
        ];
    }

    public function index(): void
    {
        Auth::requireManage();

        $filters = $this->filters();

        $logs = Logger::paginate($filters, $this->page(), (int) Config::get('app.per_page', 25));

                                                                           
        $steamIds = [];
        foreach ($logs->items as $l) {
            foreach ([(string) ($l['actor_steamid'] ?? ''), (string) ($l['target'] ?? '')] as $sid) {
                if (\App\Core\Steam::isValidSteamId64($sid)) {
                    $steamIds[] = $sid;
                }
            }
        }
        $steamIds = array_values(array_unique($steamIds));

        $this->view('logs/index', [
            'logs'      => $logs,
            'filters'   => $filters,
            'actions'   => Logger::actions(),
            'avatars'   => $steamIds ? \App\Core\Steam::avatars($steamIds) : [],
            'names'     => Logger::resolveNames($steamIds),
            'errorLog'  => ($_SERVER['HTTP_X_PANEL_SEARCH'] ?? '') === 'logs' ? null : $this->readErrorLog(),
            'csrf'      => $this->csrfToken(),
        ]);
    }

    /**
     * Read the most recent entries from error.log (newest first). Returns null
     * when the file doesn't exist yet (no errors recorded).
     * @return array{entries: string[], size: int, path: string}|null
     */
    private function readErrorLog(): ?array
    {
        $path = $this->errorLogPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        if (trim($raw) === '') {
            return ['entries' => [], 'size' => 0, 'path' => $path];
        }
        // Entries are separated by a blank line (see bootstrap error handler).
        $blocks = preg_split('/\n\s*\n/', trim($raw)) ?: [];
        // Newest first, capped so a huge file doesn't blow up the page.
        $blocks = array_reverse($blocks);
        $blocks = array_slice($blocks, 0, 200);
        return ['entries' => $blocks, 'size' => strlen($raw), 'path' => $path];
    }

    /** Stream the filtered action log as CSV (max 50 000 rows). */
    public function export(): void
    {
        Auth::requireManage();
        if (!Database::tableExists('panel_logs')) {
            $this->redirect('/logs');
        }
        [$whereSql, $params] = Logger::filterSql($this->filters());
        Logger::log('logs.export', null, http_build_query(array_filter($this->filters())));
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-' . date('Ymd-His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'created_at', 'actor_steamid', 'actor_name', 'action', 'target', 'details', 'ip'], ',', '"', '\\');
        $stmt = Database::run("SELECT id, created_at, actor_steamid, actor_name, action, target, details, ip FROM panel_logs {$whereSql} ORDER BY id DESC LIMIT 50000", $params);
        while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
            // Neutralize spreadsheet formulas in user-controlled fields.
            $row = array_map(static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v, $row);
            fputcsv($out, $row, ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /** Clear the error log (root only). */
    public function clearErrors(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $path = $this->errorLogPath();
        if (is_file($path)) {
            @file_put_contents($path, '');
        }
        Logger::log('logs.clear_errors');
        $this->flash('success', 'Error log cleared.');
        $this->redirect('/logs#errors');
    }

    /** Clear the action log (root only). */
    public function clearActions(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $removed = Logger::clearAll();
        // Re-log this action AFTER clearing, so the log isn't immediately empty
        // and there's an audit trail of who cleared it.
        Logger::log('logs.clear_actions', null, $removed . ' entries removed');
        $this->flash('success', 'Action log cleared.');
        $this->redirect('/logs#actions');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Logger;

final class LogController extends Controller
{
    /** Absolute path to the app error log written by the exception handler. */
    private function errorLogPath(): string
    {
        return BASE_PATH . '/error.log';
    }

    public function index(): void
    {
        Auth::requireManage();

        $filters = [
            'search' => trim((string) $this->query('search', '')),
            'action' => (string) $this->query('action', ''),
        ];

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

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Schema;
use App\Core\Version;

/**
 * Owner-only diagnostics, rendered without the app chrome so it still answers
 * when the normal layout is what's breaking. Shows DB reachability, which
 * panel tables/columns exist, applied migrations and the latest errors.
 */
final class HealthController extends Controller
{
    /** Panel-owned tables the code expects to find. */
    private const TABLES = [
        'panel_users', 'panel_settings', 'panel_logs', 'panel_migrations',
        'panel_server_rcon', 'panel_orders', 'panel_order_items',
        'panel_contests', 'panel_contest_winners',
        'panel_profiles', 'panel_discount_codes', 'panel_appeals', 'panel_reports',
    ];

    public function index(): void
    {
        Auth::requireRoot();

        if ($this->query('repair')) {
            Schema::sync(true);
        }

        $report = [
            'version'   => Version::current(),
            'php'       => PHP_VERSION,
            'db'        => ['ok' => false, 'error' => '', 'server' => ''],
            'tables'    => [],
            'migrations' => [],
            'pending'   => [],
            'errors'    => $this->lastErrors(),
        ];

        try {
            $pdo = Database::connection();
            $report['db']['ok'] = true;
            $report['db']['server'] = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable $e) {
            $report['db']['error'] = $e->getMessage();
        }

        if ($report['db']['ok']) {
            foreach (self::TABLES as $t) {
                $report['tables'][$t] = Database::tableExists($t);
            }
            try {
                $report['migrations'] = array_column(
                    Database::all('SELECT filename, applied_at FROM panel_migrations ORDER BY filename'),
                    'applied_at',
                    'filename'
                );
            } catch (\Throwable $e) {
                $report['migrations'] = [];
            }
            $files = array_map('basename', glob(Migrator::dir() . '/*.sql') ?: []);
            $report['pending'] = array_values(array_diff($files, array_keys($report['migrations'])));
        }

        $this->view('health/index', ['r' => $report], 'blank');
    }

    /** The last few entries from error.log (newest first). @return string[] */
    private function lastErrors(int $limit = 15): array
    {
        $path = BASE_PATH . '/error.log';
        if (!is_file($path)) {
            return [];
        }
        $raw = (string) @file_get_contents($path);
        if (trim($raw) === '') {
            return [];
        }
        $blocks = array_reverse(preg_split('/\n\s*\n/', trim($raw)) ?: []);
        return array_slice($blocks, 0, $limit);
    }
}

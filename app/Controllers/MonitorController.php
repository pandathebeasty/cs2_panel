<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Settings;
use App\Models\MonitorModel;

/** Server uptime / player history (rendered as the Monitor tab of /servers). */
final class MonitorController extends Controller
{
    private const RANGES = ['24h' => [86400, 48], '7d' => [604800, 56], '30d' => [2592000, 60]];

    public function index(): void
    {
        Auth::requireLogin();
        $range = (string) $this->query('range', '24h');
        $this->redirect('/servers' . (isset(self::RANGES[$range]) && $range !== '24h' ? '?range=' . $range : '') . '#monitor');
    }

    /** View data for the monitor tab. */
    public static function data(string $range): array
    {
        if (!isset(self::RANGES[$range])) {
            $range = '24h';
        }
        [$span, $buckets] = self::RANGES[$range];
        $now = time();
        $servers = [];
        $error = false;
        try {
            $servers = MonitorModel::overview($now);
            foreach ($servers as &$server) {
                $server['series'] = MonitorModel::series($server['id'], $now - $span, $buckets, $now);
                $server['range'] = MonitorModel::stats($server['id'], $now - $span);
            }
            unset($server);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] monitor page failed: ' . $e->getMessage());
            $error = true;
        }
        return [
            'servers' => $servers,
            'range'   => $range,
            'ranges'  => array_keys(self::RANGES),
            'enabled' => MonitorModel::enabled(),
            'error'   => $error,
        ];
    }

    public function sampleNow(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        try {
            $n = MonitorModel::sample(true);
            $this->flash('success', Lang::t('monitor.sampled', ['n' => $n]));
        } catch (\Throwable $e) {
            error_log('[CS2Panel] monitor sample failed: ' . $e->getMessage());
            $this->flash('error', Lang::t('monitor.sample_failed'));
        }
        $this->redirect('/servers#monitor');
    }
}

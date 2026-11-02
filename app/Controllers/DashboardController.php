<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\BanModel;
use App\Models\MuteModel;
use App\Models\ServerModel;
use App\Models\VipModel;
use App\Models\WarnModel;
use App\Models\StatsBackend;

final class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        // Read the cached release check; page loads never contact GitHub.
        $update = null;
        if (Auth::isRoot()) {
            try {
                $latest = \App\Core\Updater::latestCached();
                if ($latest !== null && \App\Core\Version::compare($latest['version'], \App\Core\Version::current()) > 0) {
                    $update = [
                        'version' => $latest['version'],
                        'url'     => $latest['url'] ?? ('https://github.com/' . \App\Core\Version::REPO),
                    ];
                }
            } catch (\Throwable $e) {
                $update = null;
            }
        }

        $heroServers = ServerModel::options();
        foreach ($heroServers as &$server) {
            $server['connect_url'] = \App\Core\A2S::connectUrl((string) $server['address']);
            $server['info_url'] = \App\Core\Url::to('/servers/' . (int) $server['id'] . '/info');
        }
        unset($server);

        $this->view('dashboard/index', [
            'heroServers' => $heroServers,
            'banStats'  => BanModel::stats(),
            'muteStats' => MuteModel::stats(),
            'warnStats' => WarnModel::stats(),
            'vipStats'  => VipModel::stats(),
            'serverCount' => ServerModel::count(),
            'topPlayers' => StatsBackend::top(5),
            'currentPlayerRank' => Auth::hasSteam() && Auth::steamId() !== null
                ? StatsBackend::playerRank(Auth::steamId())
                : null,
            'recentActive' => StatsBackend::recentActive(5),
            'activityHours' => \App\Models\ActivityModel::entriesByHour(6),
            'recentBans' => BanModel::paginate([], 1, 5)->items,
            'recentMutes' => MuteModel::paginate([], 1, 5)->items,
            'update' => $update,
        ]);
    }
}

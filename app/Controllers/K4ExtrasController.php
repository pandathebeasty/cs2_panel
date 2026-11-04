<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\DevMode;
use App\Models\K4ExtrasModel;

/**
 * K4-System missions, achievements and seasons (Statistics section).
 * Every page shows the signed-in player by default; ?player= or ?q= shows anyone.
 */
final class K4ExtrasController extends Controller
{
    private function guard(string $page): bool
    {
        if (K4ExtrasModel::enabled($page) || DevMode::enabled()) {
            return true;
        }
        $this->view('k4/disabled', [], 'main');
        return false;
    }

    /** @return array{0:?string,1:?array,2:array} steamId, player row, search matches */
    private function resolvePlayer(): array
    {
        $q = trim((string) $this->query('q', ''));
        $matches = [];
        $steamId = trim((string) $this->query('player', ''));
        if ($q !== '') {
            $matches = K4ExtrasModel::findPlayers($q);
            $steamId = count($matches) === 1 ? (string) $matches[0]['steam_id'] : '';
        }
        if ($steamId === '' && $q === '') {
            $steamId = (string) (Auth::steamId() ?? '');
        }
        if (!preg_match('/^\d{17}$/', $steamId)) {
            return [null, null, $matches];
        }
        $player = K4ExtrasModel::player($steamId);
        return [$player ? $steamId : null, $player, $matches];
    }

    private function common(string $page): array
    {
        [$steamId, $player, $matches] = $this->resolvePlayer();
        return [
            'page' => $page,
            'steamId' => $steamId,
            'player' => $player,
            'matches' => $matches,
            'q' => trim((string) $this->query('q', '')),
            'isSelf' => $steamId !== null && $steamId === Auth::steamId(),
            'countdowns' => K4ExtrasModel::countdowns(),
        ];
    }

    public function missions(): void
    {
        Auth::requireLogin();
        if (!$this->guard('missions')) {
            return;
        }
        $data = $this->common('missions');
        $data['mine'] = $data['steamId'] !== null ? K4ExtrasModel::playerMissions($data['steamId']) : null;
        $data['overview'] = K4ExtrasModel::missionsOverview();
        $data['popular'] = K4ExtrasModel::missionsToday();
        $data['streaks'] = K4ExtrasModel::streakLeaders();
        $this->view('k4/missions', $data);
    }

    public function achievements(): void
    {
        Auth::requireLogin();
        if (!$this->guard('achievements')) {
            return;
        }
        $data = $this->common('achievements');
        $data['list'] = K4ExtrasModel::playerAchievements($data['steamId']);
        $data['recent'] = K4ExtrasModel::recentUnlocks();
        $data['leaders'] = K4ExtrasModel::achievementLeaders();
        $data['rarity'] = K4ExtrasModel::rarity();
        $this->view('k4/achievements', $data);
    }

    public function seasons(): void
    {
        Auth::requireLogin();
        if (!$this->guard('seasons')) {
            return;
        }
        $data = $this->common('seasons');
        $seasons = K4ExtrasModel::seasons();
        $season = (string) $this->query('season', '');
        if (!in_array($season, $seasons, true)) {
            $season = $seasons[0] ?? K4ExtrasModel::now()->format('Y-m-01');
        }
        $data['seasons'] = $seasons;
        $data['season'] = $season;
        $data['current'] = K4ExtrasModel::currentSeason();
        $data['board'] = K4ExtrasModel::seasonStandings($season, $this->page(), (int) Config::get('app.per_page', 25));
        $data['history'] = $data['steamId'] !== null ? K4ExtrasModel::playerSeasons($data['steamId']) : [];
        $this->view('k4/seasons', $data);
    }
}

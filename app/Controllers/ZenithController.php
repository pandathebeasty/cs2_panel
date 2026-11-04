<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\StatsBackend;

final class ZenithController extends Controller
{
    public function ranks(): void
    {
        Auth::requireLogin();

        if (!StatsBackend::enabled() && !\App\Core\DevMode::enabled()) {
            $this->view('zenith/disabled', [], 'main');
            return;
        }

        $sort = (string) $this->query('sort', 'points');
        $dir  = (string) $this->query('dir', 'desc');
        $search = mb_substr(trim((string) $this->query('search', '')), 0, 64);
        $board = StatsBackend::ranksLeaderboard($this->page(), (int) Config::get('app.per_page', 25), $sort, $dir, $search);
        $this->view('zenith/ranks', ['board' => $board, 'csrf' => $this->csrfToken(), 'sort' => $sort, 'dir' => $dir, 'search' => $search]);
    }

    public function stats(): void
    {
        Auth::requireLogin();

        if (!StatsBackend::enabled() && !\App\Core\DevMode::enabled()) {
            $this->view('zenith/disabled', [], 'main');
            return;
        }

        $search = mb_substr(trim((string) $this->query('search', '')), 0, 64);
        $board = StatsBackend::statsLeaderboard($this->page(), (int) Config::get('app.per_page', 25), $search);
        $this->view('zenith/stats', ['board' => $board, 'csrf' => $this->csrfToken(), 'search' => $search]);
    }

    public function playtime(): void
    {
        Auth::requireLogin();

        if (!StatsBackend::enabled() && !\App\Core\DevMode::enabled()) {
            $this->view('zenith/disabled', [], 'main');
            return;
        }

        $sort = (string) $this->query('sort', 'playtime');
        $dir  = (string) $this->query('dir', 'desc');
        $search = mb_substr(trim((string) $this->query('search', '')), 0, 64);
        $board = StatsBackend::playtimeLeaderboard($this->page(), (int) Config::get('app.per_page', 25), $sort, $dir, $search);
        $this->view('zenith/playtime', ['board' => $board, 'csrf' => $this->csrfToken(), 'sort' => $sort, 'dir' => $dir, 'search' => $search]);
    }

    public function resetRank(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        if ($steamId !== '' && StatsBackend::resetRank($steamId)) {
            \App\Core\Discord::notify('discord.rank_reset_title', 'discord.act_reset_rank', 'reset', [], ['name' => \App\Core\Lang::t('discord.player'), 'steamid' => $steamId]);
            \App\Core\Logger::log('zenith.reset_rank', $steamId);
            $this->flash('success', 'Rank reset.');
        } else {
            $this->flash('error', 'Could not reset rank.');
        }
        $this->redirect('/zenith/ranks');
    }

    public function resetStats(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        if ($steamId !== '' && StatsBackend::resetStats($steamId)) {
            \App\Core\Discord::notify('discord.stats_reset_title', 'discord.act_reset_stats', 'reset', [], ['name' => \App\Core\Lang::t('discord.player'), 'steamid' => $steamId]);
            \App\Core\Logger::log('zenith.reset_stats', $steamId);
            $this->flash('success', 'Statistics reset.');
        } else {
            $this->flash('error', 'Could not reset statistics.');
        }
        $this->redirect('/zenith/stats');
    }

    /**
     * Reset ALL players' rank points. Root only, requires confirmation,
     * and takes a full DB backup first.
     */
    public function resetAllRanks(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        if ((string) $this->input('confirm', '') !== 'yes') {
            $this->flash('error', 'You must confirm before resetting all ranks.');
            $this->redirect('/zenith/ranks');
        }

        // A full DB backup on a large database can take a while; give it room and
        // never let a backup problem produce a blank page — the reset still runs.
        @set_time_limit(120);
        $backupFile = null;
        try { $backupFile = \App\Core\Backup::full(); } catch (\Throwable $e) { error_log('[CS2Panel] backup before reset failed: ' . $e->getMessage()); }

        try {
            $affected = StatsBackend::resetAllRanks();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] resetAllRanks failed: ' . $e->getMessage());
            $this->flash('error', 'Reset failed: ' . $e->getMessage());
            $this->redirect('/zenith/ranks');
        }

        $note = $backupFile ? (' Backup: ' . basename($backupFile)) : '';
        \App\Core\Discord::notify('discord.reset_all_title', \App\Core\Lang::t('discord.act_reset_all_ranks', [$affected]), 'reset');
        \App\Core\Logger::log('zenith.reset_all_ranks', null, $affected . ' players reset.' . $note);

        $msg = "All ranks reset ({$affected} players).";
        $msg .= $backupFile ? ' Full DB backup saved to db_backups/' . basename($backupFile) . '.'
                            : ' (Backup skipped or failed — check permissions on db_backups.)';
        $this->flash('success', $msg);
        $this->redirect('/zenith/ranks');
    }

    /**
     * Reset ALL players' statistics. Root only, requires confirmation,
     * and takes a full DB backup first.
     */
    public function resetAllStats(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        if ((string) $this->input('confirm', '') !== 'yes') {
            $this->flash('error', 'You must confirm before resetting all statistics.');
            $this->redirect('/zenith/stats');
        }

        @set_time_limit(120);
        $backupFile = null;
        try { $backupFile = \App\Core\Backup::full(); } catch (\Throwable $e) { error_log('[CS2Panel] backup before reset failed: ' . $e->getMessage()); }

        try {
            $affected = StatsBackend::resetAllStats();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] resetAllStats failed: ' . $e->getMessage());
            $this->flash('error', 'Reset failed: ' . $e->getMessage());
            $this->redirect('/zenith/stats');
        }

        $note = $backupFile ? (' Backup: ' . basename($backupFile)) : '';
        \App\Core\Discord::notify('discord.reset_all_title', \App\Core\Lang::t('discord.act_reset_all_stats', [$affected]), 'reset');
        \App\Core\Logger::log('zenith.reset_all_stats', null, $affected . ' players reset.' . $note);

        $msg = "All statistics reset ({$affected} players).";
        $msg .= $backupFile ? ' Full DB backup saved to db_backups/' . basename($backupFile) . '.'
                            : ' (Backup skipped or failed — check permissions on db_backups.)';
        $this->flash('success', $msg);
        $this->redirect('/zenith/stats');
    }

    /** Edit a player's rank points. */
    public function editPoints(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        $points  = (int) $this->input('points', 0);
        if ($steamId !== '' && StatsBackend::setPoints($steamId, $points)) {
            \App\Core\Logger::log('zenith.edit_points', $steamId, (string) $points);
            $this->flash('success', 'Points updated.');
        } else {
            $this->flash('error', 'Could not update points.');
        }
        $this->redirect('/zenith/ranks');
    }

    /** Edit a player's main statistics (kills/deaths/assists/headshots). */
    public function editStats(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        $fields = [
            'kills'     => (int) $this->input('kills', 0),
            'deaths'    => (int) $this->input('deaths', 0),
            'assists'   => (int) $this->input('assists', 0),
            'headshots' => (int) $this->input('headshots', 0),
        ];
        if ($steamId !== '' && StatsBackend::setStats($steamId, $fields)) {
            \App\Core\Logger::log('zenith.edit_stats', $steamId);
            $this->flash('success', 'Statistics updated.');
        } else {
            $this->flash('error', 'Could not update statistics.');
        }
        $this->redirect('/zenith/ranks');
    }

    /** Edit a player's play time (in minutes). */
    public function editPlaytime(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        $minutes = (int) $this->input('minutes', 0);
        if ($steamId !== '' && StatsBackend::setPlaytime($steamId, $minutes)) {
            \App\Core\Logger::log('zenith.edit_playtime', $steamId, $minutes . ' min');
            $this->flash('success', 'Play time updated.');
        } else {
            $this->flash('error', 'Could not update play time.');
        }
        $this->redirect('/zenith/playtime');
    }

                                             
    public function resetPlaytime(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        if ($steamId !== '' && StatsBackend::resetPlaytime($steamId)) {
            \App\Core\Discord::notify('discord.playtime_reset_title', 'discord.act_reset_playtime', 'reset', [], ['name' => \App\Core\Lang::t('discord.player'), 'steamid' => $steamId]);
            \App\Core\Logger::log('zenith.reset_playtime', $steamId);
            $this->flash('success', 'Play time reset.');
        } else {
            $this->flash('error', 'Could not reset play time.');
        }
        $this->redirect('/zenith/playtime');
    }

    /**
     * Reset ALL players' play time. Root only, requires confirmation,
     * and takes a full DB backup first.
     */
    public function resetAllPlaytime(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        if ((string) $this->input('confirm', '') !== 'yes') {
            $this->flash('error', 'You must confirm before resetting all play time.');
            $this->redirect('/zenith/playtime');
        }

        @set_time_limit(120);
        $backupFile = null;
        try { $backupFile = \App\Core\Backup::full(); } catch (\Throwable $e) { error_log('[CS2Panel] backup before reset failed: ' . $e->getMessage()); }

        try {
            $affected = StatsBackend::resetAllPlaytime();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] resetAllPlaytime failed: ' . $e->getMessage());
            $this->flash('error', 'Reset failed: ' . $e->getMessage());
            $this->redirect('/zenith/playtime');
        }

        $note = $backupFile ? (' Backup: ' . basename($backupFile)) : '';
        \App\Core\Discord::notify('discord.reset_all_title', \App\Core\Lang::t('discord.act_reset_all_playtime', [$affected]), 'reset');
        \App\Core\Logger::log('zenith.reset_all_playtime', null, $affected . ' players reset.' . $note);

        $msg = "All play time reset ({$affected} players).";
        $msg .= $backupFile ? ' Full DB backup saved to db_backups/' . basename($backupFile) . '.'
                            : ' (Backup skipped — check permissions on db_backups.)';
        $this->flash('success', $msg);
        $this->redirect('/zenith/playtime');
    }
}

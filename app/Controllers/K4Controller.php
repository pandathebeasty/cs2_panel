<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\K4SystemModel;
use App\Models\StatsBackend;

/**
 * K4-System stats pages (ranks + stats + play time), fully separate from the
 * K4-Zenith pages. Reads/writes the flat K4-System tables directly via
 * K4SystemModel (NOT the StatsBackend facade), so this controller is only
 * meaningful when the K4-System backend is selected in Settings.
 *
 * Mirrors ZenithController's actions and behaviour (resets take a DB backup,
 * notify Discord, and are logged) but targets the /k4/* routes and views.
 */
final class K4Controller extends Controller
{
    /** Show the "backend not active / tables missing" page unless K4 is live. */
    private function guard(): bool
    {
        if (StatsBackend::isK4System() && K4SystemModel::enabled()) {
            return true;
        }
        if (\App\Core\DevMode::enabled()) {
            return true;
        }
        $this->view('k4/disabled', [], 'main');
        return false;
    }

    public function ranks(): void
    {
        Auth::requireLogin();
        if (!$this->guard()) {
            return;
        }
        $sort = (string) $this->query('sort', 'points');
        $dir  = (string) $this->query('dir', 'desc');
        $search = mb_substr(trim((string) $this->query('search', '')), 0, 64);
        $board = K4SystemModel::ranksLeaderboard($this->page(), (int) Config::get('app.per_page', 25), $sort, $dir, $search);
        $this->view('k4/ranks', ['board' => $board, 'csrf' => $this->csrfToken(), 'sort' => $sort, 'dir' => $dir, 'search' => $search]);
    }

    public function stats(): void
    {
        Auth::requireLogin();
        if (!$this->guard()) {
            return;
        }
        $search = mb_substr(trim((string) $this->query('search', '')), 0, 64);
        $board = K4SystemModel::statsLeaderboard($this->page(), (int) Config::get('app.per_page', 25), $search);
        $this->view('k4/stats', ['board' => $board, 'csrf' => $this->csrfToken(), 'search' => $search]);
    }

    public function playtime(): void
    {
        Auth::requireLogin();
        if (!$this->guard()) {
            return;
        }
        $sort = (string) $this->query('sort', 'playtime');
        $dir  = (string) $this->query('dir', 'desc');
        $search = mb_substr(trim((string) $this->query('search', '')), 0, 64);
        $board = K4SystemModel::playtimeLeaderboard($this->page(), (int) Config::get('app.per_page', 25), $sort, $dir, $search);
        $this->view('k4/playtime', ['board' => $board, 'csrf' => $this->csrfToken(), 'sort' => $sort, 'dir' => $dir, 'search' => $search]);
    }

    public function resetRank(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        if ($steamId !== '' && K4SystemModel::resetRank($steamId)) {
            \App\Core\Discord::notify('discord.rank_reset_title', 'discord.act_reset_rank', 'reset', [], ['name' => \App\Core\Lang::t('discord.player'), 'steamid' => $steamId]);
            \App\Core\Logger::log('zenith.reset_rank', $steamId);
            $this->flash('success', 'Rank reset.');
        } else {
            $this->flash('error', 'Could not reset rank.');
        }
        $this->redirect('/k4/ranks');
    }

    public function resetStats(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        if ($steamId !== '' && K4SystemModel::resetStats($steamId)) {
            \App\Core\Discord::notify('discord.stats_reset_title', 'discord.act_reset_stats', 'reset', [], ['name' => \App\Core\Lang::t('discord.player'), 'steamid' => $steamId]);
            \App\Core\Logger::log('zenith.reset_stats', $steamId);
            $this->flash('success', 'Statistics reset.');
        } else {
            $this->flash('error', 'Could not reset statistics.');
        }
        $this->redirect('/k4/stats');
    }

    /** Reset ALL players' rank points. Root only, confirmation + DB backup. */
    public function resetAllRanks(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        if ((string) $this->input('confirm', '') !== 'yes') {
            $this->flash('error', 'You must confirm before resetting all ranks.');
            $this->redirect('/k4/ranks');
        }

        @set_time_limit(120);
        $backupFile = null;
        try { $backupFile = \App\Core\Backup::full(); } catch (\Throwable $e) { error_log('[CS2Panel] backup before reset failed: ' . $e->getMessage()); }

        try {
            $affected = K4SystemModel::resetAllRanks();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] k4 resetAllRanks failed: ' . $e->getMessage());
            $this->flash('error', 'Reset failed: ' . $e->getMessage());
            $this->redirect('/k4/ranks');
        }

        $note = $backupFile ? (' Backup: ' . basename($backupFile)) : '';
        \App\Core\Discord::notify('discord.reset_all_title', \App\Core\Lang::t('discord.act_reset_all_ranks', [$affected]), 'reset');
        \App\Core\Logger::log('zenith.reset_all_ranks', null, $affected . ' players reset.' . $note);

        $msg = "All ranks reset ({$affected} players).";
        $msg .= $backupFile ? ' Full DB backup saved to db_backups/' . basename($backupFile) . '.'
                            : ' (Backup skipped or failed — check permissions on db_backups.)';
        $this->flash('success', $msg);
        $this->redirect('/k4/ranks');
    }

    /** Reset ALL players' statistics. Root only, confirmation + DB backup. */
    public function resetAllStats(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        if ((string) $this->input('confirm', '') !== 'yes') {
            $this->flash('error', 'You must confirm before resetting all statistics.');
            $this->redirect('/k4/stats');
        }

        @set_time_limit(120);
        $backupFile = null;
        try { $backupFile = \App\Core\Backup::full(); } catch (\Throwable $e) { error_log('[CS2Panel] backup before reset failed: ' . $e->getMessage()); }

        try {
            $affected = K4SystemModel::resetAllStats();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] k4 resetAllStats failed: ' . $e->getMessage());
            $this->flash('error', 'Reset failed: ' . $e->getMessage());
            $this->redirect('/k4/stats');
        }

        $note = $backupFile ? (' Backup: ' . basename($backupFile)) : '';
        \App\Core\Discord::notify('discord.reset_all_title', \App\Core\Lang::t('discord.act_reset_all_stats', [$affected]), 'reset');
        \App\Core\Logger::log('zenith.reset_all_stats', null, $affected . ' players reset.' . $note);

        $msg = "All statistics reset ({$affected} players).";
        $msg .= $backupFile ? ' Full DB backup saved to db_backups/' . basename($backupFile) . '.'
                            : ' (Backup skipped or failed — check permissions on db_backups.)';
        $this->flash('success', $msg);
        $this->redirect('/k4/stats');
    }

    /** Edit a player's rank points. */
    public function editPoints(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        $points  = (int) $this->input('points', 0);
        if ($steamId !== '' && K4SystemModel::setPoints($steamId, $points)) {
            \App\Core\Logger::log('zenith.edit_points', $steamId, (string) $points);
            $this->flash('success', 'Points updated.');
        } else {
            $this->flash('error', 'Could not update points.');
        }
        $this->redirect('/k4/ranks');
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
        if ($steamId !== '' && K4SystemModel::setStats($steamId, $fields)) {
            \App\Core\Logger::log('zenith.edit_stats', $steamId);
            $this->flash('success', 'Statistics updated.');
        } else {
            $this->flash('error', 'Could not update statistics.');
        }
        $this->redirect('/k4/ranks');
    }

    /** Edit a player's play time (in minutes). */
    public function editPlaytime(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        $minutes = (int) $this->input('minutes', 0);
        if ($steamId !== '' && K4SystemModel::setPlaytime($steamId, $minutes)) {
            \App\Core\Logger::log('zenith.edit_playtime', $steamId, $minutes . ' min');
            $this->flash('success', 'Play time updated.');
        } else {
            $this->flash('error', 'Could not update play time.');
        }
        $this->redirect('/k4/playtime');
    }

                                             
    public function resetPlaytime(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        if ($steamId !== '' && K4SystemModel::resetPlaytime($steamId)) {
            \App\Core\Discord::notify('discord.playtime_reset_title', 'discord.act_reset_playtime', 'reset', [], ['name' => \App\Core\Lang::t('discord.player'), 'steamid' => $steamId]);
            \App\Core\Logger::log('zenith.reset_playtime', $steamId);
            $this->flash('success', 'Play time reset.');
        } else {
            $this->flash('error', 'Could not reset play time.');
        }
        $this->redirect('/k4/playtime');
    }

    /** Reset ALL players' play time. Root only, confirmation + DB backup. */
    public function resetAllPlaytime(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        if ((string) $this->input('confirm', '') !== 'yes') {
            $this->flash('error', 'You must confirm before resetting all play time.');
            $this->redirect('/k4/playtime');
        }

        @set_time_limit(120);
        $backupFile = null;
        try { $backupFile = \App\Core\Backup::full(); } catch (\Throwable $e) { error_log('[CS2Panel] backup before reset failed: ' . $e->getMessage()); }

        try {
            $affected = K4SystemModel::resetAllPlaytime();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] k4 resetAllPlaytime failed: ' . $e->getMessage());
            $this->flash('error', 'Reset failed: ' . $e->getMessage());
            $this->redirect('/k4/playtime');
        }

        $note = $backupFile ? (' Backup: ' . basename($backupFile)) : '';
        \App\Core\Discord::notify('discord.reset_all_title', \App\Core\Lang::t('discord.act_reset_all_playtime', [$affected]), 'reset');
        \App\Core\Logger::log('zenith.reset_all_playtime', null, $affected . ' players reset.' . $note);

        $msg = "All play time reset ({$affected} players).";
        $msg .= $backupFile ? ' Full DB backup saved to db_backups/' . basename($backupFile) . '.'
                            : ' (Backup skipped — check permissions on db_backups.)';
        $this->flash('success', $msg);
        $this->redirect('/k4/playtime');
    }
}

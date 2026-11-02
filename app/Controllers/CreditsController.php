<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Logger;
use App\Models\StoreCreditsModel;

/**
 * Store credits (schwarper/cs2-store) under the Statistics group.
 *
 * Everyone logged in can view the credits leaderboard. Editing (set / add /
 * subtract), per-player reset and reset-all are ROOT only — the view hides the
 * controls for non-root, and every write re-checks the permission server-side.
 */
final class CreditsController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        if (!StoreCreditsModel::enabled() && !\App\Core\DevMode::enabled()) {
            $this->view('credits/disabled', [], 'main');
            return;
        }

        $sort = (string) $this->query('sort', 'credits');
        $dir  = (string) $this->query('dir', 'desc');
        $board = StoreCreditsModel::leaderboard($this->page(), (int) Config::get('app.per_page', 25), $sort, $dir);
        $this->view('credits/index', [
            'board'  => $board,
            'totals' => StoreCreditsModel::totals(),
            'csrf'   => $this->csrfToken(),
            'sort'   => $sort,
            'dir'    => $dir,
        ]);
    }

    /** Set a player's credits to an absolute value. Root only. */
    public function set(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        $credits = (int) $this->input('credits', 0);
        $name    = trim((string) $this->input('name', ''));
        if ($steamId !== '' && StoreCreditsModel::set($steamId, $credits, $name)) {
            Logger::log('credits.set', $steamId, (string) $credits);
            $this->flash('success', Lang::t('credits.flash_set'));
        } else {
            $this->flash('error', Lang::t('credits.flash_fail'));
        }
        $this->redirect('/credits');
    }

    /** Add credits to a player (delta > 0). Root only. */
    public function add(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        $amount  = abs((int) $this->input('amount', 0));
        $name    = trim((string) $this->input('name', ''));
        if ($steamId !== '' && $amount > 0 && StoreCreditsModel::add($steamId, $amount, $name) !== null) {
            Logger::log('credits.add', $steamId, '+' . $amount);
            $this->flash('success', Lang::t('credits.flash_added'));
        } else {
            $this->flash('error', Lang::t('credits.flash_fail'));
        }
        $this->redirect('/credits');
    }

    /** Subtract credits from a player (clamped at 0). Root only. */
    public function subtract(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        $amount  = abs((int) $this->input('amount', 0));
        $name    = trim((string) $this->input('name', ''));
        if ($steamId !== '' && $amount > 0 && StoreCreditsModel::add($steamId, -$amount, $name) !== null) {
            Logger::log('credits.subtract', $steamId, '-' . $amount);
            $this->flash('success', Lang::t('credits.flash_subtracted'));
        } else {
            $this->flash('error', Lang::t('credits.flash_fail'));
        }
        $this->redirect('/credits');
    }

    /** Reset one player's credits to 0. Root only. */
    public function reset(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $steamId = $this->steamInput('steam_id');
        if ($steamId !== '' && StoreCreditsModel::reset($steamId)) {
            Logger::log('credits.reset', $steamId);
            $this->flash('success', Lang::t('credits.flash_reset'));
        } else {
            $this->flash('error', Lang::t('credits.flash_fail'));
        }
        $this->redirect('/credits');
    }

    /**
     * Reset ALL players' credits to 0. Root only, requires explicit
     * confirmation, and takes a full DB backup first (same pattern as the
     * ranks/stats/playtime reset-all actions).
     */
    public function resetAll(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        if ((string) $this->input('confirm', '') !== 'yes') {
            $this->flash('error', Lang::t('credits.flash_confirm'));
            $this->redirect('/credits');
        }

        @set_time_limit(120);
        $backupFile = null;
        try {
            $backupFile = \App\Core\Backup::full();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] backup before credits reset-all failed: ' . $e->getMessage());
        }

        try {
            $affected = StoreCreditsModel::resetAll();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] credits resetAll failed: ' . $e->getMessage());
            $this->flash('error', 'Reset failed: ' . $e->getMessage());
            $this->redirect('/credits');
        }

        $note = $backupFile ? (' Backup: ' . basename($backupFile)) : '';
        Logger::log('credits.reset_all', null, $affected . ' players reset.' . $note);

        $msg = Lang::t('credits.flash_reset_all', [$affected]);
        $msg .= $backupFile
            ? ' ' . Lang::t('credits.backup_saved', [basename($backupFile)])
            : ' ' . Lang::t('credits.backup_skipped');
        $this->flash('success', $msg);
        $this->redirect('/credits');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Steam;
use App\Models\AppealModel;
use App\Models\BanModel;

/**
 * Ban appeals. A player may appeal their own punishment; staff answer them.
 * A regular visitor only ever sees the appeals they filed themselves.
 */
final class AppealController extends Controller
{
    /** A player can only have this many appeals waiting for an answer. */
    private const MAX_OPEN_PER_PLAYER = 3;

                                                                              
    private function requireEnabled(): void
    {
        if (!\App\Core\Settings::bool('appeals_enabled', true)) {
            $this->redirect('/');
        }
    }

    public function index(): void
    {
        // Appeals are tied to the visitor's own SteamID (their bans / their
        // filed appeals), so a guest has nothing to show here.
        Auth::requireSteam();
        $this->requireEnabled();

        $isStaff = Auth::canModerate();
        $activeBans = BanModel::activeForSteamId((string) Auth::steamId());
        foreach ($activeBans as &$ban) {
            $ban['duration_label'] = self::durationLabel((int) $ban['duration']);
        }
        unset($ban);
        $filters = [
            'status' => (string) $this->query('status', ''),
            'search' => trim((string) $this->query('search', '')),
        ];

        $appeals = AppealModel::paginate(
            $filters,
            $this->page(),
            (int) Config::get('app.per_page', 25),
            $isStaff ? null : (string) Auth::steamId()
        );

        $steamIds = [];
        foreach ($appeals->items as $a) {
            foreach ([(string) $a['steamid64'], (string) ($a['handled_by'] ?? '')] as $sid) {
                if (Steam::isValidSteamId64($sid)) {
                    $steamIds[] = $sid;
                }
            }
        }

        $this->view('appeals/index', [
            'appeals' => $appeals,
            'filters' => $filters,
            'isStaff' => $isStaff,
            'activeBans' => $activeBans,
            'avatars' => $steamIds ? Steam::avatars(array_unique($steamIds)) : [],
            'me'      => Auth::user(),
            'csrf'    => $this->csrfToken(),
        ]);
    }

    public function store(): void
    {
        Auth::requireSteam();
        $this->requireEnabled();
        $this->verifyCsrf();

        $steamId = (string) Auth::steamId();
        $message = trim((string) $this->input('message', ''));
        $ban = BanModel::activeForSteamIdById((int) $this->input('ban_id', 0), $steamId);
        if ($ban === null) {
            $this->flash('error', Lang::t('appeals.active_ban_required'));
            $this->redirect('/appeals');
        }
        if (mb_strlen($message) < 20) {
            $this->flash('error', Lang::t('appeals.too_short'));
            $this->redirect('/appeals');
        }
        if (AppealModel::openCountFor($steamId) >= self::MAX_OPEN_PER_PLAYER) {
            $this->flash('error', Lang::t('appeals.too_many'));
            $this->redirect('/appeals');
        }

        $id = AppealModel::create(
            $steamId,
            (string) (Auth::user()['name'] ?? ''),
            $ban,
            $message
        );

        \App\Core\Discord::notify(
            'discord.appeal_title',
            'discord.act_appeal_created',
            'appeal',
            [
                'appeals.banned_by'    => (string) ($ban['admin_name'] ?? Lang::t('common.none')),
                'appeals.ban_reason'   => (string) ($ban['reason'] ?? Lang::t('common.none')),
                'appeals.ban_duration' => self::durationLabel((int) $ban['duration']),
                'appeals.message'      => self::excerpt($message),
                'discord.appeal_id'    => '#' . $id,
            ],
            ['name' => (string) (Auth::user()['name'] ?? ''), 'steamid' => $steamId, 'avatar' => (string) (Auth::user()['avatar'] ?? '')]
        );

        Logger::log('appeal.create', $steamId, (string) (Auth::user()['name'] ?? '') . ' | #' . $id);
        $this->flash('success', Lang::t('appeals.submitted'));
        $this->redirect('/appeals');
    }

    public function resolve(string $id): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

        $appeal = AppealModel::find((int) $id);
        if ($appeal === null) {
            $this->redirect('/appeals');
        }

        $status = (string) $this->input('status', 'open');
        $reply  = (string) $this->input('admin_reply', '');
        if (!in_array($status, AppealModel::STATUSES, true)) {
            $status = 'open';
        }

        $unbanRequested = $status === 'approved' && (string) $this->input('unban', '') === '1';
        $unbanDone = false;
        $unbanReason = trim((string) $this->input('unban_reason', ''));
        $ban = null;
        if ($unbanRequested) {
            $ban = BanModel::activeForSteamIdById((int) ($appeal['ban_id'] ?? 0), (string) $appeal['steamid64']);
            if ($ban === null) {
                $this->flash('error', Lang::t('appeals.ban_inactive'));
                $this->redirect('/appeals');
            }
            if ($unbanReason === '') {
                $unbanReason = $reply !== '' ? $reply : Lang::t('appeals.approved_reason');
            }
        }

        $pdo = \App\Core\Database::connection();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($unbanRequested && $ban !== null) {
                $adminId = \App\Models\ServerModel::adminIdBySteamId(Auth::steamId());
                BanModel::unban((int) $ban['id'], $adminId, $unbanReason);
                $unbanDone = true;
            }
            AppealModel::resolve(
                (int) $id,
                $status,
                $reply,
                (string) Auth::steamId(),
                (string) (Auth::user()['name'] ?? '')
            );
            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[CS2Panel] appeal resolution failed: ' . $e->getMessage());
            $this->flash('error', Lang::t('appeals.resolve_failed'));
            $this->redirect('/appeals');
        }

        \App\Core\Discord::notify(
            'discord.appeal_title',
            $status === 'approved' ? 'discord.act_appeal_approved' : ($status === 'rejected' ? 'discord.act_appeal_rejected' : 'discord.act_appeal_updated'),
            'appeal',
            [
                'appeals.ban_details' => '#' . (int) ($appeal['ban_id'] ?? 0),
                'common.status'       => Lang::t('appeals.status_' . $status),
                'appeals.reply'       => $reply !== '' ? self::excerpt($reply) : Lang::t('common.none'),
                'appeals.unban_choice' => $unbanRequested
                    ? Lang::t($unbanDone ? 'appeals.unban_done' : 'appeals.unban_not_done')
                    : Lang::t('appeals.ban_kept'),
                'discord.appeal_id'   => '#' . (int) $id,
            ],
            ['name' => (string) ($appeal['player_name'] ?? ''), 'steamid' => (string) $appeal['steamid64']]
        );

        if ($unbanDone && $ban !== null) {
            \App\Core\Discord::notify(
                'discord.unban_title',
                'discord.act_unban',
                'unban',
                ['discord.reason' => $unbanReason],
                [
                    'name' => (string) ($ban['player_name'] ?? $appeal['player_name'] ?? ''),
                    'steamid' => (string) $appeal['steamid64'],
                ]
            );
            Logger::log('ban.unban', (string) $appeal['steamid64'], $unbanReason . ' | appeal #' . (int) $id);
        }

        Logger::log(
            'appeal.' . ($status === 'approved' ? 'approve' : 'reject'),
            (string) $appeal['steamid64'],
            (string) ($appeal['player_name'] ?? '') . ' | #' . (int) $id . ($unbanDone ? ' | unban' : ' | ban kept')
        );
        $this->flash('success', Lang::t('appeals.updated'));
        $this->redirect('/appeals');
    }

    public function delete(string $id): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        $appeal = AppealModel::find((int) $id);
        AppealModel::delete((int) $id);

        if ($appeal !== null) {
            \App\Core\Discord::notify(
                'discord.appeal_title',
                'discord.act_appeal_deleted',
                'reset',
                ['discord.appeal_id' => '#' . (int) $id],
                ['name' => (string) ($appeal['player_name'] ?? ''), 'steamid' => (string) $appeal['steamid64']]
            );
        }

        Logger::log('appeal.delete', (string) ($appeal['steamid64'] ?? ''), '#' . (int) $id);
        $this->flash('success', Lang::t('appeals.deleted'));
        $this->redirect('/appeals');
    }

    /** Discord embed fields are capped at 1024 characters. */
    private static function excerpt(string $text): string
    {
        $text = trim($text);
        return mb_strlen($text) > 500 ? mb_substr($text, 0, 500) . '…' : $text;
    }

    private static function durationLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return Lang::t('common.permanent');
        }
        if ($minutes % 1440 === 0) {
            $amount = intdiv($minutes, 1440);
            return $amount . ' ' . Lang::t($amount === 1 ? 'time.day' : 'time.days');
        }
        if ($minutes % 60 === 0) {
            $amount = intdiv($minutes, 60);
            return $amount . ' ' . Lang::t($amount === 1 ? 'time.hour' : 'time.hours');
        }
        return $minutes . ' ' . Lang::t($minutes === 1 ? 'time.minute' : 'time.minutes');
    }
}

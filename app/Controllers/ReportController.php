<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Steam;
use App\Models\BanModel;
use App\Models\ReportModel;
use App\Models\ServerModel;

/**
 * Player reports. Staff see every report; a regular visitor only sees the ones
 * they filed themselves.
 */
final class ReportController extends Controller
{
    /** Reports one visitor may file per hour. */
    private const MAX_PER_HOUR = 5;

                                                                              
    private function requireEnabled(): void
    {
        if (!\App\Core\Settings::bool('reports_enabled', true)) {
            $this->redirect('/');
        }
    }

    public function index(): void
    {
        // Reports are tied to the visitor's own SteamID (the ones they filed),
        // so a guest has nothing to show and can't file — prompt for Steam.
        Auth::requireSteam();
        $this->requireEnabled();

        $isStaff = Auth::canModerate();
        $filters = [
            'status' => (string) $this->query('status', ''),
            'search' => trim((string) $this->query('search', '')),
        ];

        $reports = ReportModel::paginate(
            $filters,
            $this->page(),
            (int) Config::get('app.per_page', 25),
            $isStaff ? null : (string) Auth::steamId()
        );

        $steamIds = [];
        foreach ($reports->items as $r) {
            foreach ([(string) $r['reporter_steamid'], (string) $r['target_steamid']] as $sid) {
                if (Steam::isValidSteamId64($sid)) {
                    $steamIds[] = $sid;
                }
            }
        }

        $this->view('reports/index', [
            'reports' => $reports,
            'filters' => $filters,
            'isStaff' => $isStaff,
            'servers' => ServerModel::options(),
            'avatars' => $steamIds ? Steam::avatars(array_unique($steamIds)) : [],
            'csrf'    => $this->csrfToken(),
        ]);
    }

    public function store(): void
    {
        Auth::requireSteam();
        $this->requireEnabled();
        $this->verifyCsrf();

        $reporter = (string) Auth::steamId();
        $target   = $this->steamInput('target_steamid');
        $message  = trim((string) $this->input('message', ''));

        if (!Steam::isValidSteamId64($target)) {
            $this->flash('error', Lang::t('reports.bad_target'));
            $this->redirect('/reports');
        }
        if ($target === $reporter) {
            $this->flash('error', Lang::t('reports.self'));
            $this->redirect('/reports');
        }
        if (mb_strlen($message) < 20) {
            $this->flash('error', Lang::t('reports.too_short'));
            $this->redirect('/reports');
        }
        if (ReportModel::recentCountFor($reporter) >= self::MAX_PER_HOUR) {
            $this->flash('error', Lang::t('reports.too_many'));
            $this->redirect('/reports');
        }

        // Best-effort display name for the reported account.
        $targetName = trim((string) $this->input('target_name', ''));
        if ($targetName === '') {
            $prof = \App\Core\SteamOpenID::fetchProfile($target);
            $targetName = (string) ($prof['name'] ?? '');
        }

        $id = ReportModel::create([
            'reporter_steamid' => $reporter,
            'reporter_name'    => (string) (Auth::user()['name'] ?? ''),
            'target_steamid'   => $target,
            'target_name'      => $targetName,
            'type'             => (string) $this->input('type', 'other'),
            'server_id'        => $this->input('server_id'),
            'message'          => $message,
        ]);

        $type = (string) $this->input('type', 'other');
        \App\Core\Discord::notify(
            'discord.report_title',
            'discord.act_report_created',
            'report',
            [
                'reports.type'     => Lang::t('reports.type_' . (in_array($type, ReportModel::TYPES, true) ? $type : 'other')),
                'reports.reporter' => (string) (Auth::user()['name'] ?? $reporter) . ' (' . $reporter . ')',
                'common.server'    => self::serverLabel($this->input('server_id')),
                'reports.message'  => self::excerpt($message),
                'discord.report_id' => '#' . $id,
            ],
            ['name' => $targetName, 'steamid' => $target]
        );

        Logger::log('report.create', $target, ($targetName !== '' ? $targetName : $target) . ' | #' . $id);
        $this->flash('success', Lang::t('reports.submitted'));
        $this->redirect('/reports');
    }

    public function resolve(string $id): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

        $report = ReportModel::find((int) $id);
        if ($report === null) {
            $this->redirect('/reports');
        }

        $status = (string) $this->input('status', 'open');
        $reply  = (string) $this->input('admin_reply', '');
        if (!in_array($status, ReportModel::STATUSES, true)) {
            $status = 'open';
        }
        $requestedType = (string) $report['type'];
        $applyAction = $status === 'accepted'
            && (string) $this->input('apply_action', '') === '1'
            && in_array($requestedType, ['ban', 'mute', 'gag', 'silence'], true);
        $duration = max(0, min(525600, (int) $this->input('action_duration', 60)));
        $actionReason = trim((string) $this->input('action_reason', ''));
        if ($actionReason === '') {
            $actionReason = $reply !== '' ? $reply : (string) $report['message'];
        }
        $actionReason = mb_substr($actionReason, 0, 255);

        if ($applyAction && $requestedType === 'ban' && BanModel::activeForSteamId((string) $report['target_steamid'])) {
            $this->flash('error', Lang::t('reports.target_already_banned'));
            $this->redirect('/reports');
        }

        $appliedAction = '';
        $pdo = \App\Core\Database::connection();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($applyAction) {
                $actorSteamId = (string) (Auth::steamId() ?? '0');
                $actorName = (string) (Auth::user()['name'] ?? 'Web');
                $serverId = !empty($report['server_id']) ? (int) $report['server_id'] : null;

                if ($requestedType === 'ban') {
                    BanModel::create([
                        'player_name' => (string) ($report['target_name'] ?? ''),
                        'player_steamid' => (string) $report['target_steamid'],
                        'player_ip' => null,
                        'admin_steamid' => $actorSteamId,
                        'admin_name' => $actorName,
                        'reason' => $actionReason,
                        'duration' => $duration,
                        'server_id' => $serverId,
                    ]);
                    $appliedAction = Lang::t('reports.type_ban');
                } else {
                    $muteType = match ($requestedType) {
                        'gag' => 'GAG',
                        'silence' => 'SILENCE',
                        default => 'MUTE',
                    };
                    \App\Models\MuteModel::create([
                        'player_name' => (string) ($report['target_name'] ?? ''),
                        'player_steamid' => (string) $report['target_steamid'],
                        'admin_steamid' => $actorSteamId,
                        'admin_name' => $actorName,
                        'reason' => $actionReason,
                        'duration' => $duration,
                        'type' => $muteType,
                        'server_id' => $serverId,
                    ]);
                    $appliedAction = Lang::t('reports.type_' . $requestedType);
                }
            }

            ReportModel::resolve(
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
            error_log('[CS2Panel] report resolution failed: ' . $e->getMessage());
            $this->flash('error', Lang::t('reports.resolve_failed'));
            $this->redirect('/reports');
        }

        if ($applyAction) {
            $fields = [
                'discord.reason' => $actionReason,
                'discord.duration' => $duration > 0 ? Lang::t('discord.minutes_n', [$duration]) : Lang::t('discord.permanent'),
            ];
            if ($requestedType === 'ban') {
                \App\Core\Discord::notify(
                    'discord.ban_title', 'discord.act_ban', 'ban', $fields,
                    ['name' => (string) ($report['target_name'] ?? ''), 'steamid' => (string) $report['target_steamid']]
                );
                Logger::log('ban.create', (string) $report['target_steamid'], $actionReason . ' | from report #' . (int) $id);
            } else {
                $actionKey = match ($requestedType) {
                    'gag' => 'discord.act_gag',
                    'silence' => 'discord.act_silence',
                    default => 'discord.act_mute',
                };
                \App\Core\Discord::notify(
                    'discord.mute_title', $actionKey, 'mute', $fields,
                    ['name' => (string) ($report['target_name'] ?? ''), 'steamid' => (string) $report['target_steamid']]
                );
                Logger::log('mute.create', (string) $report['target_steamid'], $requestedType . ': ' . $actionReason . ' | from report #' . (int) $id);
            }
        }

        \App\Core\Discord::notify(
            'discord.report_title',
            $status === 'accepted' ? 'discord.act_report_accepted' : ($status === 'rejected' ? 'discord.act_report_rejected' : 'discord.act_report_updated'),
            'report',
            [
                'reports.type'      => Lang::t('reports.type_' . (string) $report['type']),
                'common.status'     => Lang::t('reports.status_' . (in_array($status, ReportModel::STATUSES, true) ? $status : 'open')),
                'reports.reply'     => $reply !== '' ? self::excerpt($reply) : Lang::t('common.none'),
                'reports.action_applied' => $appliedAction !== '' ? $appliedAction : Lang::t('common.none'),
                'discord.report_id' => '#' . (int) $id,
            ],
            ['name' => (string) ($report['target_name'] ?? ''), 'steamid' => (string) $report['target_steamid']]
        );

        Logger::log(
            'report.' . ($status === 'accepted' ? 'accept' : 'reject'),
            (string) $report['target_steamid'],
            (string) ($report['target_name'] ?? '') . ' | #' . (int) $id . ($appliedAction !== '' ? ' | action: ' . $appliedAction : '')
        );
        $this->flash('success', Lang::t('reports.updated'));
        $this->redirect('/reports');
    }

    public function delete(string $id): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        $report = ReportModel::find((int) $id);
        ReportModel::delete((int) $id);

        if ($report !== null) {
            \App\Core\Discord::notify(
                'discord.report_title',
                'discord.act_report_deleted',
                'reset',
                ['discord.report_id' => '#' . (int) $id],
                ['name' => (string) ($report['target_name'] ?? ''), 'steamid' => (string) $report['target_steamid']]
            );
        }

        Logger::log('report.delete', (string) ($report['target_steamid'] ?? ''), '#' . (int) $id);
        $this->flash('success', Lang::t('reports.deleted'));
        $this->redirect('/reports');
    }

    /** Discord embed fields are capped at 1024 characters. */
    private static function excerpt(string $text): string
    {
        $text = trim($text);
        return mb_strlen($text) > 500 ? mb_substr($text, 0, 500) . '…' : $text;
    }

    private static function serverLabel(mixed $serverId): string
    {
        $sid = (int) $serverId;
        if ($sid <= 0) {
            return Lang::t('common.all_servers');
        }
        $server = ServerModel::find($sid);
        return $server ? (string) ($server['hostname'] ?: $server['address']) : Lang::t('common.all_servers');
    }
}

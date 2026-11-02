<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\BanModel;
use App\Models\ServerModel;

final class BanController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'search'    => trim((string) $this->query('search', '')),
            'status'    => (string) $this->query('status', ''),
            'server_id' => $this->query('server_id'),
        ];

        $bans = BanModel::paginate($filters, $this->page(), (int) Config::get('app.per_page', 25));

        // Steam avatars for the players on this page (admin cells show no avatar).
        $steamIds = [];
        foreach ($bans->items as $b) {
            $sid = (string) ($b['player_steamid'] ?? '');
            if (\App\Core\Steam::isValidSteamId64($sid)) {
                $steamIds[] = $sid;
            }
        }
        $avatars = $steamIds ? \App\Core\Steam::avatars(array_unique($steamIds)) : [];

        $this->view('bans/index', [
            'bans'    => $bans,
            'removals' => \App\Models\SanctionDetailsModel::removals('bans', $bans->items),
            'filters' => $filters,
            'servers' => ServerModel::options(),
            'avatars' => $avatars,
            'csrf'    => $this->csrfToken(),
        ]);
    }

    public function show(string $id): void
    {
        Auth::requireLogin();
        $ban = BanModel::find((int) $id);
        if (!$ban) {
            http_response_code(404);
            $this->view('errors/404', [], 'main');
            return;
        }
        $this->view('bans/show', [
            'ban' => $ban,
            'removals' => \App\Models\SanctionDetailsModel::removals('bans', [$ban]),
            'csrf' => $this->csrfToken(),
        ]);
    }

    public function store(): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

        $steamid = $this->steamInput('player_steamid');
        $ip      = trim((string) $this->input('player_ip', ''));
        if ($steamid === '' && $ip === '') {
            $this->flash('error', 'You must specify a SteamID64 or an IP.');
            $this->redirect('/bans');
        }

        $name = trim((string) $this->input('player_name', '')) ?: null;
        $reason = trim((string) $this->input('reason', 'No reason')) ?: 'No reason';
        $duration = (int) $this->input('duration', 0);

        $banId = BanModel::create([
            'player_name'    => $name,
            'player_steamid' => $steamid !== '' ? $steamid : null,
            'player_ip'      => $ip !== '' ? $ip : null,
            'admin_steamid'  => Auth::steamId() ?? '0',
            'admin_name'     => Auth::user()['name'] ?? 'Web',
            'reason'         => $reason,
            'duration'       => $duration,
            'server_id'      => $this->input('server_id'),
        ]);

        \App\Core\Discord::notifyRecord(
            'discord.ban_title',
            'discord.act_ban',
            'ban',
            BanModel::find($banId)
        );
        \App\Core\Logger::log('ban.create', $steamid ?: $ip, $reason);

        $this->flash('success', 'Ban added successfully.');
        $this->redirect('/bans');
    }

    public function update(string $id): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

        $ban = BanModel::find((int) $id);
        if (!$ban) {
            $this->flash('error', 'Ban not found.');
            $this->redirect('/bans');
        }

        $reason = trim((string) $this->input('reason', 'No reason')) ?: 'No reason';
        $duration = (int) $this->input('duration', 0);
        $name = trim((string) $this->input('player_name', ''));
        $steamid = $this->steamInput('player_steamid');
        $ip = trim((string) $this->input('player_ip', ''));
        $status = strtoupper(trim((string) $this->input('status', (string) $ban['status'])));

        $changes = [
            'player_name'    => $name,
            'player_steamid' => $steamid,
            'player_ip'      => $ip,
            'reason'         => $reason,
            'duration'       => $duration,
            'status'         => $status,
        ];
        if (array_key_exists('server_id', $_POST)) $changes['server_id'] = $this->input('server_id');
        $liftAdminId = strtoupper((string) $ban['status']) !== 'UNBANNED' && $status === 'UNBANNED'
            ? ServerModel::adminIdBySteamId(Auth::steamId())
            : null;
        try {
            BanModel::update((int) $id, $changes, $liftAdminId, \App\Core\Lang::t('sanctions.edit_lift_reason'));
        } catch (\InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/bans');
        }

        \App\Core\Discord::notifyRecord(
            'discord.ban_title',
            'discord.act_ban_update',
            'ban',
            BanModel::find((int) $id)
        );
        \App\Core\Logger::log('ban.update', $steamid ?: ($ban['player_steamid'] ?? (string) $id), $reason);

        $this->flash('success', 'Ban updated.');
        $this->redirect('/bans');
    }

    /**
     * Delete ALL bans. Root only, and requires an explicit confirmation checkbox.
     */
    public function deleteAll(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        // Require the "I understand" confirmation checkbox to be ticked.
        if ((string) $this->input('confirm', '') !== 'yes') {
            $this->flash('error', 'You must confirm before deleting all bans.');
            $this->redirect('/bans');
        }

        // Take a full database backup BEFORE destroying anything.
        $backupFile = \App\Core\Backup::full();

        $removed = BanModel::deleteAll();

        $backupNote = $backupFile ? (' Backup: ' . basename($backupFile)) : '';
        \App\Core\Discord::notify(
            'discord.ban_deleteall_title',
            \App\Core\Lang::t('discord.act_ban_deleteall', [$removed]),
            'reset',
            ['discord.affected_records' => (string) $removed]
        );
        \App\Core\Logger::log('ban.delete_all', null, $removed . ' bans removed.' . $backupNote);

        if ($backupFile) {
            $this->flash('success', "All bans deleted ({$removed} removed). Full DB backup saved to db_backups/" . basename($backupFile) . '.');
        } else {
            $this->flash('success', "All bans deleted ({$removed} removed). (Backup skipped — check server permissions on the db_backups folder.)");
        }
        $this->redirect('/bans');
    }

    /** Delete a single ban row (destructive; keeps no history). */
    public function delete(string $id): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

                                                                       
        $ban = BanModel::find((int) $id);
        BanModel::delete((int) $id);

        \App\Core\Discord::notifyRecord(
            'discord.ban_delete_title',
            'discord.act_ban_delete',
            'reset',
            $ban
        );
        \App\Core\Logger::log('ban.delete', $ban['player_steamid'] ?? $id);
        $this->flash('success', 'Ban deleted.');
        $this->redirect('/bans');
    }

    public function unban(string $id): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

        $reason = trim((string) $this->input('reason', ''));
        $ban = BanModel::find((int) $id);
        $adminId = ServerModel::adminIdBySteamId(Auth::steamId());
        BanModel::unban((int) $id, $adminId, $reason);

        \App\Core\Discord::notifyRecord(
            'discord.unban_title',
            'discord.act_unban',
            'unban',
            BanModel::find((int) $id),
            ['discord.reversal_reason' => $reason !== '' ? $reason : \App\Core\Lang::t('discord.no_reason')]
        );
        \App\Core\Logger::log('ban.unban', $ban['player_steamid'] ?? (string) $id, $reason);

        $this->flash('success', 'Player has been unbanned.');
        $this->redirect('/bans');
    }
}

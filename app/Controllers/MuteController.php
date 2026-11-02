<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\MuteModel;
use App\Models\ServerModel;

final class MuteController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'search' => trim((string) $this->query('search', '')),
            'type'   => (string) $this->query('type', ''),
            'status' => (string) $this->query('status', ''),
        ];

        $mutes = MuteModel::paginate($filters, $this->page(), (int) Config::get('app.per_page', 25));

        // Steam avatars for the players AND the acting admins on this page.
        $steamIds = [];
        foreach ($mutes->items as $m) {
            foreach ([(string) ($m['player_steamid'] ?? ''), (string) ($m['admin_steamid'] ?? '')] as $sid) {
                if (\App\Core\Steam::isValidSteamId64($sid)) {
                    $steamIds[] = $sid;
                }
            }
        }
        $avatars = $steamIds ? \App\Core\Steam::avatars(array_unique($steamIds)) : [];

        $this->view('mutes/index', [
            'mutes'   => $mutes,
            'removals' => \App\Models\SanctionDetailsModel::removals('mutes', $mutes->items),
            'filters' => $filters,
            'servers' => ServerModel::options(),
            'avatars' => $avatars,
            'csrf'    => $this->csrfToken(),
        ]);
    }

    public function store(): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

        $steamid = $this->steamInput('player_steamid');
        if ($steamid === '') {
            $this->flash('error', 'SteamID64 is required.');
            $this->redirect('/mutes');
        }

        $name = trim((string) $this->input('player_name', '')) ?: null;
        $reason = trim((string) $this->input('reason', 'No reason')) ?: 'No reason';
        $duration = (int) $this->input('duration', 0);
        $type = (string) $this->input('type', 'GAG');

        $muteId = MuteModel::create([
            'player_name'    => $name,
            'player_steamid' => $steamid,
            'admin_steamid'  => Auth::steamId() ?? '0',
            'admin_name'     => Auth::user()['name'] ?? 'Web',
            'reason'         => $reason,
            'duration'       => $duration,
            'type'           => $type,
            'server_id'      => $this->input('server_id'),
        ]);

        $storedMute = MuteModel::find($muteId);
        $actionKey = match ((string) ($storedMute['type'] ?? '')) {
            'GAG'     => 'discord.act_gag',
            'SILENCE' => 'discord.act_silence',
            default   => 'discord.act_mute',
        };
        \App\Core\Discord::notifyRecord(
            'discord.mute_title',
            $actionKey,
            'mute',
            $storedMute
        );
        \App\Core\Logger::log('mute.create', $steamid, $type . ': ' . $reason);

        $this->flash('success', 'Chat restriction added.');
        $this->redirect('/mutes');
    }

    public function unmute(string $id): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

        $reason = trim((string) $this->input('reason', ''));
        $adminId = ServerModel::adminIdBySteamId(Auth::steamId());
        MuteModel::unmute((int) $id, $adminId, $reason);

        \App\Core\Discord::notifyRecord(
            'discord.unmute_title',
            'discord.act_unmute',
            'unmute',
            MuteModel::find((int) $id),
            ['discord.reversal_reason' => $reason !== '' ? $reason : \App\Core\Lang::t('discord.no_reason')]
        );
        \App\Core\Logger::log('mute.unmute', (string) $id, $reason);

        $this->flash('success', 'Restriction lifted.');
        $this->redirect('/mutes');
    }

    /** Delete a single mute/gag/silence row (destructive; keeps no history). */
    public function delete(string $id): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

        $mute = MuteModel::find((int) $id);
        MuteModel::delete((int) $id);

        \App\Core\Discord::notifyRecord(
            'discord.mute_delete_title',
            'discord.act_mute_delete',
            'reset',
            $mute
        );
        \App\Core\Logger::log('mute.delete', $mute['player_steamid'] ?? $id);
        $this->flash('success', 'Chat restriction deleted.');
        $this->redirect('/mutes');
    }
}

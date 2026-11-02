<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\ServerModel;
use App\Models\WarnModel;

final class WarnController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'search' => trim((string) $this->query('search', '')),
            'status' => (string) $this->query('status', ''),
        ];

        $warns = WarnModel::paginate($filters, $this->page(), (int) Config::get('app.per_page', 25));

        // Steam avatars for the players AND the acting admins on this page.
        $steamIds = [];
        foreach ($warns->items as $w) {
            foreach ([(string) ($w['player_steamid'] ?? ''), (string) ($w['admin_steamid'] ?? '')] as $sid) {
                if (\App\Core\Steam::isValidSteamId64($sid)) {
                    $steamIds[] = $sid;
                }
            }
        }
        $avatars = $steamIds ? \App\Core\Steam::avatars(array_unique($steamIds)) : [];

        $this->view('warns/index', [
            'warns'   => $warns,
            'filters' => $filters,
            'servers' => ServerModel::options(),
            'avatars' => $avatars,
            'csrf'    => $this->csrfToken(),
        ]);
    }

    public function store(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $steamid = $this->steamInput('player_steamid');
        if ($steamid === '') {
            $this->flash('error', 'SteamID64 is required.');
            $this->redirect('/warns');
        }

        $name = trim((string) $this->input('player_name', '')) ?: null;
        $reason = trim((string) $this->input('reason', 'No reason')) ?: 'No reason';

        $warnId = WarnModel::create([
            'player_name'    => $name,
            'player_steamid' => $steamid,
            'admin_steamid'  => Auth::steamId() ?? '0',
            'admin_name'     => Auth::user()['name'] ?? 'Web',
            'reason'         => $reason,
            'duration'       => (int) $this->input('duration', 0),
            'server_id'      => $this->input('server_id'),
        ]);

        \App\Core\Discord::notifyRecord(
            'discord.warn_title',
            'discord.act_warn',
            'warn',
            WarnModel::find($warnId)
        );
        \App\Core\Logger::log('warn.create', $steamid, $reason);

        $this->flash('success', 'Warning added.');
        $this->redirect('/warns');
    }

    public function expire(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $warn = WarnModel::find((int) $id);
        WarnModel::expire((int) $id);

        \App\Core\Discord::notifyRecord(
            'discord.warn_expire_title',
            'discord.act_warn_expire',
            'warn',
            WarnModel::find((int) $id)
        );
        \App\Core\Logger::log('warn.expire', $warn['player_steamid'] ?? (string) $id);
        $this->flash('success', 'Warning expired.');
        $this->redirect('/warns');
    }

    /** Delete a single warning row (destructive; keeps no history). */
    public function delete(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $warn = WarnModel::find((int) $id);
        WarnModel::delete((int) $id);

        \App\Core\Discord::notifyRecord(
            'discord.warn_delete_title',
            'discord.act_warn_delete',
            'reset',
            $warn
        );
        \App\Core\Logger::log('warn.delete', $warn['player_steamid'] ?? (string) $id);
        $this->flash('success', 'Warning deleted.');
        $this->redirect('/warns');
    }
}

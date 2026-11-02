<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\VipModel;

final class VipController extends Controller
{
    public function index(): void
    {
        // The VIP list exposes every VIP's SteamID, so it is admin-only —
        // plain users must not see it.
        Auth::requireManage();

        // In dev preview show the full UI even without a DB/table.
        if (!VipModel::enabled() && !\App\Core\DevMode::enabled()) {
            $this->view('vip/disabled', [], 'main');
            return;
        }

        $filters = [
            'search' => trim((string) $this->query('search', '')),
            'group'  => (string) $this->query('group', ''),
        ];

        $vips = VipModel::paginate($filters, $this->page(), (int) Config::get('app.per_page', 25));

        // Groups the admin defined in Settings (for the add/edit dropdown).
        $configuredGroups = VipModel::configuredGroups();
        // For the filter, merge configured + groups that already exist in the DB.
        $filterGroups = array_values(array_unique(array_merge($configuredGroups, VipModel::groups())));

        $this->view('vip/index', [
            'vips'    => $vips,
            'filters' => $filters,
            'groups'  => $filterGroups,
            'vipGroups' => $configuredGroups,
            'servers' => VipModel::servers(),
            'csrf'    => $this->csrfToken(),
        ]);
    }

    public function store(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $steamid = $this->steamInput('steamid64');
        if (!\App\Core\Steam::isValidSteamId64($steamid)) {
            $this->flash('error', 'Invalid SteamID64.');
            $this->redirect('/vip');
        }
        $name  = trim((string) $this->input('name', 'VIP'));
        $group = trim((string) $this->input('group', 'VIP')) ?: 'VIP';
        $days  = (int) $this->input('days', 30);
        $sid   = (int) $this->input('sid', 0);

        // Don't overwrite an existing VIP — error out so the admin uses Edit/Extend.
        if (VipModel::exists($steamid, $sid)) {
            $this->flash('error', 'This player already has VIP on that server. Use Edit or Extend instead.');
            $this->redirect('/vip');
        }

        VipModel::create($steamid, $name, $group, $days, $sid);
        \App\Core\ServerSync::refreshVip();

        \App\Core\Discord::notifyVip(
            'discord.act_vip_added',
            $steamid,
            $sid,
            VipModel::find($steamid, $sid)
        );
        \App\Core\Logger::log('vip.add', $steamid, $group);

        $this->flash('success', 'VIP added.');
        $this->redirect('/vip');
    }

    public function extend(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $steamid = $this->steamInput('steamid64');
        $sid  = (int) $this->input('sid', 0);
        $days = (int) $this->input('days', 30);

        VipModel::extend($steamid, $sid, $days);
        \App\Core\ServerSync::refreshVip();

        \App\Core\Discord::notifyVip(
            'discord.act_vip_extended',
            $steamid,
            $sid,
            VipModel::find($steamid, $sid),
            ['discord.added' => \App\Core\Lang::t('discord.days_n', [$days])]
        );
        \App\Core\Logger::log('vip.extend', $steamid, '+' . $days . ' days');

        $this->flash('success', 'VIP extended.');
        $this->redirect('/vip');
    }

    public function update(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $steamid = $this->steamInput('steamid64');
        $sid   = (int) $this->input('sid', 0);
        $name  = trim((string) $this->input('name', 'VIP'));
        $group = trim((string) $this->input('group', 'VIP')) ?: 'VIP';

        // Expiry mode: "permanent" -> 0, otherwise use the split date + 24h time fields.
        $mode = (string) $this->input('expiry_mode', 'date');
        if ($mode === 'permanent') {
            $expires = 0;
        } else {
            $when = $this->combineDateTime((string) $this->input('expires_at_date', ''), (string) $this->input('expires_at_time', ''));
            $ts = $when !== '' ? strtotime($when) : false;
            $expires = $ts !== false ? $ts : time();
        }

        VipModel::update($steamid, $sid, $name, $group, $expires);
        \App\Core\ServerSync::refreshVip();

        \App\Core\Discord::notifyVip(
            'discord.act_vip_updated',
            $steamid,
            $sid,
            VipModel::find($steamid, $sid)
        );
        \App\Core\Logger::log('vip.update', $steamid, $group);

        $this->flash('success', 'VIP updated.');
        $this->redirect('/vip');
    }

    public function remove(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $steamid = $this->steamInput('steamid64');
        $sid = (int) $this->input('sid', 0);

        // Look up the real player name BEFORE deleting, so the Discord embed
        // shows who actually lost VIP (not the literal word "VIP").
        $existing = VipModel::find($steamid, $sid);
        VipModel::remove($steamid, $sid);

        \App\Core\Discord::notifyVip(
            'discord.act_vip_removed',
            $steamid,
            $sid,
            $existing
        );
        \App\Core\Logger::log('vip.remove', $steamid);

        $this->flash('success', 'VIP removed.');
        $this->redirect('/vip');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Steam;
use App\Core\SteamOpenID;
use App\Models\AdminModel;
use App\Models\PanelUserModel;
use App\Models\TwoFactorModel;

/**
 * Authentication is done exclusively through Steam OpenID.
 * There is no username/password path anywhere in the panel.
 */
final class AuthController extends Controller
{
    /** Start login: redirect to Steam. */
    public function login(): void
    {
        header('Location: ' . SteamOpenID::redirectUrl());
        exit;
    }

    /** Enter the panel as a guest (no Steam identity). */
    public function guest(): void
    {
        if (!Auth::hasSteam()) {
            Auth::loginGuest();
        }
        $this->redirect('/');
    }

    /** Steam OpenID callback. */
    public function callback(): void
    {
        $steamId = SteamOpenID::validate($_GET);
        if ($steamId === null) {
            $this->flash('error', Lang::t('auth.failed'));
            $this->redirect('/login');
        }

                                                                     
        $admin = AdminModel::findBySteamId($steamId);
        $isSuper = in_array($steamId, Config::get('super_admins', []), true);
        $panelUser = PanelUserModel::findBySteamId($steamId);

        // The panel is open to EVERYONE who logs in with Steam. Visitors who are
        // not a game admin, panel user, or config super admin are logged in as a
        // plain "user": they can browse read-only pages and use the Store, but
        // have no admin flags and cannot manage anything.
        // Web permissions are PANEL-ONLY now: they come solely from the
        // panel_users role (or config super_admin), never from server @css
        // flags. Normalize the stored role to the two-role model.
        $rawRole = $panelUser['role'] ?? null;
        if ($isSuper) {
            $panelRole = 'owner';
        } elseif ($rawRole === 'root' || $rawRole === 'owner') {
            $panelRole = 'owner';
        } elseif ($rawRole !== null && $rawRole !== '') {
            $panelRole = 'moderator'; // legacy 'admin'/'moderator' -> moderator
        } else {
            $panelRole = null; // plain user
        }

        // Profile (name/avatar) via Steam Web API if a key is set, otherwise from DB.
        $profile = SteamOpenID::fetchProfile($steamId);

        $sessionUser = [
            'steamid'    => $steamId,
            'name'       => $profile['name'] ?? ($panelUser['name'] ?? ($admin['player_name'] ?? 'Admin')),
            'avatar'     => $profile['avatar'] ?? Steam::avatarPlaceholder(),
            // Server flags are kept only for reference; they no longer grant any
            // panel access.
            'flags'      => $admin ? AdminModel::effectiveFlags($admin) : [],
            'panel_role' => $panelRole,
            'can_manage' => $panelRole === 'owner',
            'immunity'   => (int) ($admin['immunity'] ?? ($admin['group_immunity'] ?? 0)),
        ];

        if ($panelRole !== null && TwoFactorModel::enabled($steamId)) {
            Auth::beginTwoFactor($sessionUser);
            $this->redirect('/2fa');
        }

        Auth::login($sessionUser);
        if ($panelRole !== null) {
            Logger::log('auth.login', $steamId, $panelRole);
        }

        $this->redirect('/');
    }

    public function logout(): void
    {
        if (Auth::isAdmin()) {
            Logger::log('auth.logout', (string) Auth::steamId());
        }
        Auth::logout();
        $this->redirect('/login');
    }

    /** Login page (Steam button). */
    public function loginPage(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }
        $servers = [];
        $unavailable = false;
        try {
            $servers = \App\Models\ServerModel::options();
        } catch (\RuntimeException $error) {
            error_log('[CS2Panel] Login server overview failed: ' . $error->getMessage());
            $unavailable = true;
        }
        foreach ($servers as &$server) {
            $server['hostname'] = $server['hostname'] ?: $server['address'];
            $server['connect_url'] = \App\Core\A2S::connectUrl((string) $server['address']);
            $server['info_url'] = \App\Core\Url::to('/login/servers/' . (int) $server['id'] . '/info');
        }
        unset($server);
        $this->view('auth/login', ['heroServers' => $servers, 'serverOverviewUnavailable' => $unavailable], 'blank');
    }
}

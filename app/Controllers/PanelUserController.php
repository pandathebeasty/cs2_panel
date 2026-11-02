<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Steam;
use App\Models\PanelUserModel;

/**
 * PANEL user management (site access), separate from game admins.
 * Only root can modify these users.
 */
final class PanelUserController extends Controller
{
    public function index(): void
    {
        Auth::requireRoot();
        $users = PanelUserModel::all();
        // Steam avatars for the listed users (batch; cached, needs steam.api_key).
        $avatars = Steam::avatars(array_map(static fn($u) => (string) $u['steamid64'], $users));
        $this->view('panel_users/index', [
            'users'   => $users,
            'avatars' => $avatars,
            'csrf'    => $this->csrfToken(),
        ]);
    }

    public function store(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        $steamid = $this->steamInput('steamid64');
        if (!Steam::isValidSteamId64($steamid)) {
            $this->flash('error', 'Invalid SteamID64 (17 digits).');
            $this->redirect('/panel-users');
        }

        $name = trim((string) $this->input('name', 'Moderator')) ?: 'Moderator';
        $role = PanelUserModel::normalizeRole((string) $this->input('role', 'moderator'));

        PanelUserModel::create($steamid, $name, $role, Auth::steamId());

        \App\Core\Logger::log('panel_user.add', $steamid, $role);
        $this->flash('success', 'Panel user added.');
        $this->redirect('/panel-users');
    }

    public function update(string $id): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        $user = PanelUserModel::find((int) $id);
        if (!$user) {
            $this->flash('error', 'Panel user not found.');
            $this->redirect('/panel-users');
        }

        $name = trim((string) $this->input('name', 'Moderator')) ?: 'Moderator';
        $role = PanelUserModel::normalizeRole((string) $this->input('role', 'moderator'));

        // Don't allow demoting the last owner away from 'owner'.
        $wasOwner = PanelUserModel::normalizeRole((string) $user['role']) === 'owner';
        if ($wasOwner && $role !== 'owner' && PanelUserModel::ownerCount() <= 1) {
            $this->flash('error', 'You cannot demote the last owner.');
            $this->redirect('/panel-users');
        }

        PanelUserModel::update((int) $id, $name, $role);
        \App\Core\Logger::log('panel_user.update', $user['steamid64'] ?? $id, $role);
        $this->flash('success', 'Panel user updated.');
        $this->redirect('/panel-users');
    }

    public function delete(string $id): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        // Do not allow deleting the last owner
        $user = Database::one('SELECT * FROM panel_users WHERE id = ?', [(int) $id]);
        if ($user && PanelUserModel::normalizeRole((string) $user['role']) === 'owner' && PanelUserModel::ownerCount() <= 1) {
            $this->flash('error', 'You cannot delete the last owner.');
            $this->redirect('/panel-users');
        }

        PanelUserModel::delete((int) $id);
        \App\Core\Logger::log('panel_user.delete', $id);
        $this->flash('success', 'Panel user removed.');
        $this->redirect('/panel-users');
    }
}

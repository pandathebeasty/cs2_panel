<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Steam;
use App\Models\ProfileModel;

/**
 * The visitor's own panel profile: Steam identity (read-only, from the login)
 * plus the Discord handle they choose to show on the site.
 */
final class ProfileController extends Controller
{
    /** JSON payload backing the profile mini-UI in the topbar. */
    public function card(): void
    {
        Auth::requireSteam();
        $user    = Auth::user() ?? [];
        $steamId = (string) Auth::steamId();

        $this->json([
            'ok'          => true,
            'name'        => (string) ($user['name'] ?? ''),
            'avatar'      => (string) ($user['avatar'] ?? Steam::avatarPlaceholder()),
            'steamid'     => $steamId,
            'profile_url' => Steam::profileUrl($steamId),
            'role'        => Auth::role(),
            'discord_tag' => ProfileModel::discordTag($steamId),
        ]);
    }

    public function save(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();

        $steamId = (string) Auth::steamId();
        $tag     = trim((string) $this->input('discord_tag', ''));
        $isAjax  = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

        if ($tag !== '' && !ProfileModel::isValidDiscordTag($tag)) {
            if ($isAjax) {
                $this->json(['ok' => false, 'message' => Lang::t('profile.discord_invalid')]);
            }
            $this->flash('error', Lang::t('profile.discord_invalid'));
            $this->redirect('/');
        }

        ProfileModel::setDiscordTag($steamId, $tag);
        Logger::log('profile.update', $steamId, $tag !== '' ? 'Discord: ' . $tag : 'Discord cleared');

        if ($isAjax) {
            $this->json(['ok' => true, 'discord_tag' => $tag, 'message' => Lang::t('profile.saved')]);
        }
        $this->flash('success', Lang::t('profile.saved'));
        $this->redirect('/');
    }
}

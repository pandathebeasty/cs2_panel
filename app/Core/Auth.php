<?php

declare(strict_types=1);

namespace App\Core;

/**
 * User session + permissions based on the flags from sa_admins / sa_groups.
 *
 * CSS flags used:
 *   @css/root      -> full access
 *   @css/ban       -> manage bans
 *   @css/unban
 *   @css/chat      -> mute/gag/silence
 *   @css/kick
 *   @css/slay
 *   @css/generic   -> basic admin
 *   @css/rcon      -> RCON commands
 * The panel uses the same flags as the plugin, so permissions are not duplicated.
 */
final class Auth
{
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['guest'], $_SESSION['2fa_pending']);
        $_SESSION['user'] = $user;
    }

    /**
     * Steam login succeeded but the account has 2FA: keep the user aside until
     * the authenticator code is verified on /2fa.
     */
    public static function beginTwoFactor(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['user'], $_SESSION['guest']);
        $_SESSION['2fa_pending'] = ['user' => $user, 'at' => time()];
    }

    /** The user waiting for a 2FA code, or null (expires after 5 minutes). */
    public static function pendingTwoFactor(): ?array
    {
        $pending = $_SESSION['2fa_pending'] ?? null;
        if (!is_array($pending) || time() - (int) ($pending['at'] ?? 0) > 300) {
            unset($_SESSION['2fa_pending']);
            return null;
        }
        return $pending['user'] ?? null;
    }

    public static function completeTwoFactor(): bool
    {
        $user = self::pendingTwoFactor();
        if ($user === null) {
            return false;
        }
        $user['mfa'] = true;
        self::login($user);
        return true;
    }

    public static function markTwoFactorVerified(): void
    {
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['mfa'] = true;
        }
    }

    /** Staff must set up 2FA first when the owner made it mandatory. */
    private static function enforceTwoFactor(): void
    {
        if (!self::isAdmin() || !empty($_SESSION['user']['mfa']) || !\App\Models\TwoFactorModel::required()) {
            return;
        }
        if (\App\Models\TwoFactorModel::enabled((string) self::steamId())) {
            self::markTwoFactorVerified();
            return;
        }
        header('Location: ' . Url::to('/account/security?required=1'));
        exit;
    }

    /**
     * Browse the panel as a guest: a session with NO Steam identity. Guests see
     * every public (read-only) page and the non-admin sidebar, but any page that
     * needs a SteamID (skins, store purchases, appeals, reports, VIP, profile)
     * shows a "log in with Steam" prompt via requireSteam() instead of content.
     */
    public static function loginGuest(): void
    {
        session_regenerate_id(true);
        unset($_SESSION['user']);
        $_SESSION['guest'] = true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Is there ANY active session (a real Steam user OR a guest)? This gates the
     * read-only pages: guests pass. Pages that truly need a Steam identity use
     * requireSteam()/hasSteam() instead.
     */
    public static function check(): bool
    {
        return isset($_SESSION['user']) || !empty($_SESSION['guest']);
    }

    /** Logged in with a real Steam identity (not a guest). */
    public static function hasSteam(): bool
    {
        return isset($_SESSION['user']);
    }

    /** Browsing as a guest (session active, but no Steam identity). */
    public static function isGuest(): bool
    {
        return empty($_SESSION['user']) && !empty($_SESSION['guest']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function steamId(): ?string
    {
        return $_SESSION['user']['steamid'] ?? null;
    }

    /** @return string[] the list of effective flags */
    public static function flags(): array
    {
        return $_SESSION['user']['flags'] ?? [];
    }

    public static function isSuperAdmin(): bool
    {
        $supers = Config::get('super_admins', []);
        return in_array(self::steamId(), $supers, true);
    }

    /**
     * The panel role from panel_users, if any. Normalized to the current model:
     * 'owner' or 'moderator'. Legacy values are mapped ('root' -> 'owner',
     * 'admin' -> 'moderator') so old sessions/rows keep working.
     */
    public static function panelRole(): ?string
    {
        $r = $_SESSION['user']['panel_role'] ?? null;
        if ($r === null || $r === '') {
            return null;
        }
        if ($r === 'root' || $r === 'owner') {
            return 'owner';
        }
        // Everything else that was a panel role becomes a moderator.
        return 'moderator';
    }

    /**
     * Owner: the top tier with full access. Config super_admin or the 'owner'
     * (legacy 'root') panel role. Web permissions are panel-only now — server
     * @css flags no longer grant any panel access.
     */
    public static function isOwner(): bool
    {
        return self::isSuperAdmin() || self::panelRole() === 'owner';
    }

    /** Back-compat alias. Owner == the old "root". */
    public static function isRoot(): bool
    {
        return self::isOwner();
    }

    /**
     * "Can edit the site" = owner only. There is no separate can_manage toggle
     * anymore: being owner grants full management automatically.
     */
    public static function canManage(): bool
    {
        return self::isOwner();
    }

    /**
     * Can perform moderation actions (bans, mutes/gags): owner or a panel
     * moderator. This is what gates the moderation controls on the web.
     */
    public static function canModerate(): bool
    {
        return self::isOwner() || self::panelRole() === 'moderator';
    }

    /**
     * Kept for compatibility with older call sites. Panel access is no longer
     * derived from server flags — a "flag" check now maps to moderation rights.
     */
    public static function can(string $flag): bool
    {
        return self::canModerate();
    }

    /**
     * Is the current visitor a panel staff member (owner or moderator)? Used to
     * separate the read-only "user" experience from the staff panel. Server
     * admin flags no longer count toward this.
     */
    public static function isAdmin(): bool
    {
        return self::panelRole() !== null || self::isSuperAdmin();
    }

    /**
     * A plain "user": logged in with Steam but with no panel role at all.
     * They can browse the read-only pages and buy from the Store. Guests are
     * NOT users (they have no Steam identity).
     */
    public static function isUser(): bool
    {
        return self::hasSteam() && !self::isAdmin();
    }

    /** Coarse role label for display / logic: owner | moderator | user | guest. */
    public static function role(): string
    {
        if (!self::hasSteam()) {
            return 'guest';
        }
        if (self::isOwner()) {
            return 'owner';
        }
        if (self::panelRole() === 'moderator') {
            return 'moderator';
        }
        return 'user';
    }

    /**
     * Stop execution if there is no active session at all. Guests pass — this
     * only gates against fully anonymous visitors, so the read-only pages are
     * visible to guests and real users alike.
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
                                                                               
            // Show the panel login screen before starting Steam OpenID.
            header('Location: ' . Url::to('/login'));
            exit;
        }
    }

    /**
     * Require a real Steam identity for pages/actions keyed on a SteamID (skins,
     * store purchases, appeals, reports, VIP, profile). Behaviour by visitor:
     *   - anonymous (no session) -> redirect to the login page;
     *   - guest (session, no Steam) -> render the "log in with Steam" prompt in
     *     place of the page content and stop (they stay on the panel chrome);
     *   - real Steam user -> pass through.
     */
    public static function requireSteam(): void
    {
        if (!self::check()) {
            header('Location: ' . Url::to('/login'));
            exit;
        }
        if (!self::hasSteam()) {
            http_response_code(403);
            View::render('errors/steam_required', [], 'main');
            exit;
        }
    }

    /**
     * Stop execution unless the user can moderate (owner or moderator). Kept
     * named requireFlag for compatibility; the flag argument is now ignored.
     */
    public static function requireFlag(string $flag): void
    {
        self::requireModerate();
    }

    /** Stop execution unless the user can moderate (owner or moderator). */
    public static function requireModerate(): void
    {
        self::requireLogin();
        if (!self::canModerate()) {
            http_response_code(403);
            View::render('errors/403', [], 'main');
            exit;
        }
        self::enforceTwoFactor();
    }

    /** Stop execution if not the owner (for settings & panel management). */
    public static function requireRoot(): void
    {
        self::requireLogin();
        if (!self::isOwner()) {
            http_response_code(403);
            View::render('errors/403', [], 'main');
            exit;
        }
        self::enforceTwoFactor();
    }

    /** Stop execution if the user cannot edit the site (owner only). */
    public static function requireManage(): void
    {
        self::requireLogin();
        if (!self::canManage()) {
            http_response_code(403);
            View::render('errors/403', [], 'main');
            exit;
        }
        self::enforceTwoFactor();
    }
}

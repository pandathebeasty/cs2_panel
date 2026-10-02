# CS2 Panel

A web-based administration panel for Counter-Strike 2 servers, written in plain PHP with no framework dependency. It provides moderation, player statistics, a VIP system, a PayPal store, contests, and a native WeaponPaints loadout editor behind a single Steam-authenticated interface.

**Stack:** PHP 8.1+ · MySQL/MariaDB · Steam OpenID · License-key activation

**Integrates with:** [CS2-SimpleAdmin](https://github.com/daffyyyy/CS2-SimpleAdmin) · [K4-Zenith](https://github.com/K4ryuu/K4-Zenith) · [K4-System](https://github.com/K4ryuu/K4-System) · [VIPCore](https://github.com/partiusfabaa/cs2-VIPCore) · [WeaponPaints](https://github.com/Nereziel/cs2-WeaponPaints)

---

## Features

| Area | Description |
|---|---|
| Moderation | Bans, mutes/gags, and warnings. Paginated and searchable, with Steam avatars. Per-row deletion plus owner-only bulk deletion with an automatic database backup. |
| Appeals & Reports | Players appeal their own punishments and report others; staff resolve or delete from the panel. |
| Servers | Live A2S status, current map with preview and player count, an RCON console, and a `steam://connect` action. Unreachable servers never block the interface. |
| Store | PayPal shop for VIP and admin packages: cart, discount codes, checkout discounts, configurable fees, donations, order history, refunds, and per-purchase email and Discord receipts. |
| Contests | Rank- or playtime-based giveaways that automatically grant VIP to the top players and announce results on Discord. Winner selection follows the active statistics backend. |
| VIP | Add, extend, edit, and remove VIP via VIPCore, with configurable groups and automatic SteamID64 to account-id conversion. |
| Statistics | Ranks, statistics, and playtime leaderboards with per-player and global resets. The backend is selectable between K4-Zenith (JSON storage) and K4-System (flat tables); the two are mutually exclusive. Includes CS2 rank artwork — competitive skill-group badges and the Premier CS-Rating plate coloured by tier. |
| Skins | A native WeaponPaints loadout editor: weapon skins (paint, wear, seed, StatTrak and count, nametag), knives, gloves, agents (CT/T), music kits, and pins/coins. Supports per-team scoping (T, CT, or both). Authenticates through the panel and reads/writes the plugin's `wp_player_*` tables. |
| Admins | Management of game admins, admin groups, and separate panel users. |
| Logs | A filterable action log (actor, action, target, IP) and error log. |

## Appearance and localization

- Server-side theming, applied consistently across every device, with presets, a custom theme builder, and additional animated themes.
- Light, dark, and system modes; collapsible sidebar; responsive layout.
- Available in English, Romanian, Russian, and German.

---

## Installation

1. Upload the project. Point the web server's document root at the project root; `index.php` is the single entry point and `assets/` is served from the same location.
2. Grant PHP write access to `config/`, `db_backups/`, and `assets/img/`.
3. Open the site and complete the `/install` wizard: license key, database connection and prefix, site URL, owner SteamID64, integrations, and optional VIP groups and Discord webhook. The wizard includes the statistics backend selector (K4-Zenith or K4-System) and the WeaponPaints (Skins) toggle with its table prefix.
4. Sign in with Steam. All remaining configuration lives under Settings.

<details>
<summary>Nginx server block</summary>

```nginx
server {
    root /path/to/cs2-panel;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
}
```
</details>

**Requirements:** PHP 8.1 or newer with the `pdo_mysql`, `curl`, `fileinfo`, `zip`, and `openssl` extensions; MySQL or MariaDB (the same database used by SimpleAdmin); Apache or Nginx. A [Steam Web API key](https://steamcommunity.com/dev/apikey) is optional and enables player names and avatars.

## Integrations

- **CS2-SimpleAdmin** — moderation data.
- **VIPCore** — VIP management.
- **K4-Zenith / K4-System** — statistics backend (one is selected during setup).
- **WeaponPaints** — the Skins loadout editor.

Each integration is optional and configured from the panel; when a plugin's tables are absent, its section is simply hidden.
## Security

Database access uses prepared statements, all state-changing requests are CSRF-protected, authentication is handled through Steam OpenID, and destructive actions require owner confirmation and take an automatic database backup first. `config.php` is excluded from version control and is never overwritten by the updater.

---

## License

Copyright (c) panda.4179 . All rights reserved.

This software is proprietary and distributed under a per-installation license key.
Redistribution, resale, or sharing of the source or license key is not permitted without the
author's written consent.

For licensing and support, contact panda.4179 on Discord.

# CS2 Panel

A PHP admin and community panel for Counter-Strike 2 servers. It sits on top of the MySQL/MariaDB tables that popular CounterStrikeSharp plugins already use, so there is no separate game-side component to install. No framework and no build step for the PHP side: a small front controller, a router, plain PHP views and PDO.

Version: see [VERSION](VERSION).

## Supported plugins

The panel reads and writes the plugins' own tables.

| Plugin | What the panel uses it for |
|---|---|
| CS2-SimpleAdmin (`sa_*` tables) | Bans, mutes, warns, admins, admin groups, server list |
| VIPCore | VIP users and groups (`account_id` = SteamID64 − 76561197960265728) |
| K4-Zenith | Ranks, stats, playtime (JSON columns) |
| K4-System | Ranks, stats, playtime (flat tables) |
| WeaponPaints | Skin selection per player (`wp_` prefix, configurable) |
| cs2-store (schwarper) | Store credits |

Zenith and K4-System are alternative stats backends; `StatsBackend` picks the one that is configured.

## Requirements

- PHP 8.x with `pdo_mysql`, `curl`, `mbstring` and `zip` (the updater needs `zip`).
- MySQL or MariaDB reachable from the web host.
- Apache with `mod_rewrite` and `.htaccess` enabled (the bundled `.htaccess` also blocks `.env`, `.ini`, `.log`, `.sql` and `.md` files).
- A Steam Web API key for Steam login.
- RCON access to the game servers, only if you want RCON, live players or live VIP refresh.

## Installation

1. Upload the files to the web root (or a subfolder; links are rewritten to the base path).
2. Open `/install`. The wizard asks for the database details, the Steam API key and the initial super admin, writes `config/config.php` and creates the panel tables from `database/panel_schema.sql`.
3. Alternatively copy `config/config.example.php` to `config/config.php` and fill it in by hand.
4. Pending migrations in `database/migrations` (001–017) are applied by `Migrator`; applied ones are listed on `/health`.

### Configuration keys (`config/config.php`)

`db`, `sa_prefix`, `zenith`, `vipcore`, `steam` (`api_key`, `realm`, `return_url`), `super_admins`, `app`, `rcon` (timeout, reload commands), `query`. Everything else (Discord, SMTP, PayPal, store, themes, notification toggles) is stored in the database and edited from the Settings page.

## Features

**Moderation**
- Bans, mutes and warns: create, edit, expire/unban, delete, bulk update, bulk delete.
- Details popup per sanction, including the unban history. Unbanned entries no longer show as permanent.
- Ban appeals and player reports, with resolve and delete.
- Admins and admin groups, with a canonical flag list (custom flags are allowed and preserved).
- Panel users with roles: guest, user, admin, owner, root. Super admins come from the config.

**Servers**
- Server list with live A2S status (map, players/max players).
- RCON console per server, with a live player list and player actions.
- After a VIP or admin change, `ServerSync` sends the configured reload commands over RCON so players don't need to reconnect. Failures are logged and never roll back the change.

**VIP and contests**
- VIP add, extend, update and remove, with a pending queue: a prize of a different group waits until the current VIP lapses.
- Contests on rank or playtime, scheduled or manual. Winners are drawn from the stats leaderboard, paged 50 rows at a time. Players who can't use the prize are skipped and the next one is taken (permanent VIP when extending is on, any active VIP when it is off; credits-only contests skip nobody).
- Prize types: VIP and store credits. Grant per winner or all, redraw, resend, delete.
- Creating a contest can back up the database and reset ranks first. The backup is a plain `.sql` dump written with PDO only, to `db_backups/`, so it works on shared hosting.
- Discord announcement when a contest starts and ends.

**Stats**
- Ranks, stats and playtime for Zenith and K4-System: list, search, edit points/stats/playtime, reset one or all.
- Credits: set, add, subtract, reset.
- Skins page (WeaponPaints) with rarity colours generated from the ByMykel CSGO-API (`tools/gen_rarity_colors.php`, also refreshed by the maintenance tick).
- Benefits page with SVG icons per VIP group, including premium variants and Supreme.

**Store**
- Packages (VIP, admin perks, credits), cart, discount codes, optional donations.
- PayPal checkout with webhook, sandbox/live credentials, optional fee passed to the buyer.
- Orders: refund, re-grant, plus test-mode tools (mark paid, test email, test Discord).
- Admin perks granted by the store are revoked automatically when their duration ends; stale pending orders are auto-cancelled.

**Notifications**
- Discord webhooks, with a per-category toggle (bans, mutes, warns, appeals, reports, VIP, admins, groups, resets, …).
- SMTP mail (host, port, user, password, ssl/tls, from) for order emails and alerts, with a test button. Without SMTP configured the mailer falls back to PHP mail.

**Other**
- Steam OpenID login, guest mode, personal profile card and rank.
- Chat log viewer, action log and error log.
- Health page (`/health`, owner only): DB reachability, expected tables and columns, applied migrations, latest errors. It renders without the main layout so it still works when the layout is what's broken.
- Self-updater based on GitHub tags (checked at most every 6 hours, cached), applied in steps from `/update`.
- Languages: English, Romanian, German, Russian (`app/Lang`).

## Maintenance tasks

`Maintenance::run()` does all periodic work: start due contests, finalize ended ones, purge old history, apply queued VIP prizes, revoke expired store perks, cancel stale orders, sample active players for the dashboard graph and refresh skin rarity colours.

Two ways to trigger it:
- A cron job calling `/cron?token=<token>` every minute (recommended).
- Without cron, a throttled best-effort tick runs on normal page loads.

Each task is isolated, so one failing never blocks the rest.

## Themes and effects

Themes are CSS files in `assets/css/themes/`. A file registers itself through an `@theme id:… | name:… | accent:… | tag:…` header comment, so adding a theme means dropping in a file.

Bundled: Carbon HUD, Cyber Grid, Fireworks, Liquid Glass, Liquid Mesh, Mono Market, Nord, Nuke Facility, Obsidian Studio, SourceBans, Starfield, Terminal Green, Warzone Camo.

`assets/js/effects.js` has three effect modes (Auto, Full, Reduced). Auto drops to reduced when the device struggles. GSAP and Lenis are vendored locally; regenerate with `npm run vendor:motion` (`tools/vendor-motion.cjs`).

## Layout

```
index.php            front controller
app/routes.php       all routes
app/Core/            Router, View, Database, Auth, Config, Settings, Rcon, A2S,
                     Discord, Mailer, PayPal, Store, Maintenance, Migrator,
                     Updater, Backup, Themes, Lang, ServerSync, ...
app/Controllers/     one controller per area
app/Models/          queries; plugin tables are accessed here only
app/Views/           PHP templates; layouts: main, blank, install
app/Lang/            en, ro, de, ru
assets/              css (app, design, themes), js, vendored motion libs, icons
config/              config.example.php
database/            panel_schema.sql, migrations/
tools/               asset vendoring, rarity colour generator
```

## Security notes

- CSRF tokens on all POST forms; prepared statements through PDO.
- Role and flag checks per route (`Auth::requireFlag`, `requireModerate`, `requireManage`, `requireRoot`).
- `/cron` and the PayPal webhook are the only endpoints not behind a login; the first needs the token.
- Sensitive file types are denied by `.htaccess`.
- Dev mode (`.dev/DEV_MODE` file or `CS2PANEL_DEV=1`) skips the installer and fakes a root session. Never enable it in production.
- The footer credit ("Powered by -panda") is checked by `Brand::enforce`; removing it blocks the main layout.

## Support the developer

A "Support developer" link sits in the footer of every page. The URL is `Brand::SUPPORT_URL` in `app/Core/Brand.php`.

## Notes

- The panel-side license system has been removed from this version.
- Not every area has automated tests; there is no test suite in the repo.

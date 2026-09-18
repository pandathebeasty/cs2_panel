# CS2 Panel

A modern, fast web admin panel for **Counter-Strike 2** servers. It works with
[CS2-SimpleAdmin](https://github.com/daffyyyy/CS2-SimpleAdmin) and integrates with
[K4-Zenith](https://github.com/K4ryuu/K4-Zenith) (ranks + stats) and
[VIPCore](https://github.com/partiusfabaa/cs2-VIPCore).

Written in plain PHP (no heavy framework), with a focus on performance and
reliability: PDO prepared statements everywhere, query-level pagination (never
loads a whole table into memory), and RCON/A2S calls with short timeouts so a
dead game server never hangs the panel or throws 500s.

> Current version is shown in the panel under **Updates**. Releases are tagged on
> GitHub and the panel can update itself (see [Auto-update](#auto-update)).

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Deployment layouts (subfolder / flat host)](#deployment-layouts)
- [Permissions (flags)](#permissions-flags)
- [Themes](#themes)
- [Internationalization](#internationalization)
- [Discord notifications](#discord-notifications)
- [Auto-update](#auto-update)
- [Integrations](#integrations)
- [Project structure](#project-structure)
- [Security notes](#security-notes)

---

## Features

### Moderation
- **Dashboard** — live counters (active/total bans, mutes, warns, VIPs, servers),
  a **Top Players** table (K4-Zenith) with Steam avatars, and **Recent bans /
  Recent mutes** side-by-side with avatars and profile links.
- **Bans** — paginated, searchable/filterable list; create, edit (reason +
  duration), re-ban, unban, per-row **delete**, and a root-only **Delete all**
  (with confirmation + automatic DB backup). Separate IP column.
- **Mutes / Gags / Silences** — list, add, lift, and per-row delete.
- **Warnings** — list, add, expire, and per-row delete.
- Every list has a per-row delete gated on the matching permission.

### Servers
- **Server overview** — rich cards per server showing **live status** (online/
  offline), **current map** (with a preview image + friendly name), **player
  count** (current / max), RCON readiness, and a **Connect** button
  (`steam://connect/ip:port`). Live data is fetched via the A2S query protocol
  with a short timeout, so an unreachable server just shows "offline".
- **RCON console** — send commands to any server through an AJAX console with a
  short timeout (never blocks the UI).
- Add / edit / delete servers from the UI.
- Map images: drop `<mapname>.png` files into `assets/img/maps/` (a colored
  gradient tile is generated automatically when no image exists).

### VIP (VIPCore)
- List, add, extend, edit and remove VIPs. SteamID64 ↔ VIPCore `account_id`
  conversion is automatic.
- **Configurable VIP groups**: define your groups in Settings (one per line) and
  they appear as a **dropdown** when adding/editing a VIP. Also settable during
  install. Falls back to a free-text field if none are configured.
- **Ignored groups**: hide internal/among-us groups from the panel list + counts.

### Stats (K4-Zenith)
- **Ranks** leaderboard (points, rank, kills/deaths, playtime, last seen) with
  sortable columns.
- **Play Time** view.
- Root-only **reset rank / reset stats** per player, and **reset all** (with
  confirmation + DB backup).

### Administration
- **Game admins** — full CRUD over `sa_admins` with flag editing.
- **Admin groups** — CRUD with flags + per-server assignment.
- **Panel users** — people who can access the *panel* (separate from game
  admins), with roles **root / admin / moderator**, an editable display name,
  and a "can manage the site" toggle. Root can edit name/role/permissions of any
  panel user (with a guard so the last root can't be removed/demoted).
- **Logs** — two tabs:
  - **Action Log**: every panel action is recorded (who / what / target / IP /
    when) with expandable detail rows, icons, filters and search.
  - **Error Log**: reads the panel's `error.log` (newest first) as styled,
    severity-colored cards; root can clear it.

### Appearance & UX
- **Server-side themes** — the selected theme, mode and accent are stored in the
  database, so the look is **shared across every device** (set it once, applies
  on desktop and mobile for everyone). No per-browser localStorage drift.
- **Preset themes** + a **custom theme builder** (full light/dark palette).
- **Animated themes** — a drop-in folder of "living" themes with constant motion
  (drifting gradients, glows, starfields). See [Themes](#themes).
- **Light / Dark / System** mode toggle (respects the OS setting).
- **Collapsible sidebar** (icons-only) and collapsible sections, smooth
  animations, mobile-friendly responsive layout with an off-canvas menu.
- Auto-dismissing flash toasts, live local clock, Steam avatars, and tasteful
  micro-interactions. Honors `prefers-reduced-motion` (while keeping an
  explicitly chosen animated theme alive).

### Platform
- **Setup wizard** at first run (`/install`) — DB, realm, root SteamID, VIP
  groups, integrations; sends a Discord notification when installation finishes.
- **Multi-language** UI: English (default), Romanian, Russian, German.
- **Discord notifications** for all actions, in a CSS-BANS style embed — fully
  translatable and customizable (see [Discord](#discord-notifications)).
- **Auto-update from GitHub** with a one-click upgrade and DB migrations.
- **Long-lived login** — Steam sessions persist for 30 days (sliding window).
- **Author credit** in the footer with a light-touch tamper check.

---

## Requirements

- **PHP 8.1+** with extensions: `pdo_mysql`, `curl`, `fileinfo`, `zip`
  (`zip` is required for auto-update; everything else works without it).
- **MySQL / MariaDB** — the same database used by CS2-SimpleAdmin.
- A web server (Apache with `mod_rewrite`, or Nginx).
- Optional: a **Steam Web API key** for player names + avatars —
  https://steamcommunity.com/dev/apikey

---

## Installation

1. Upload the project to your server. The recommended **DocumentRoot is the
   `public/` folder** (see [Deployment layouts](#deployment-layouts) for a flat
   shared-host layout).
2. Make sure PHP can write to `config/`, `db_backups/`, and the uploads folder
   under `assets/img/`.
3. Open the site — you'll be redirected to `/install`.
4. Complete the wizard:
   - database credentials (same as SimpleAdmin) + table prefix (`sa_`),
   - the site URL (realm) — used for Steam login,
   - the first **root** SteamID64,
   - integrations (VIPCore / K4-Zenith) and Zenith table names,
   - optional: VIP groups and a Discord webhook.
5. After install, sign in with Steam. Everything else is configured from
   **Settings**.

The wizard writes `config/config.php`, runs `database/panel_schema.sql`
(creating `panel_users`, `panel_settings`, `panel_logs`) and creates the root user.

### Nginx example

```nginx
server {
    root /path/to/cs2-panel/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
}
```

---

## Deployment layouts

The front controller auto-detects two layouts and also supports running under a
**subfolder** (e.g. `https://example.com/panel`):

- **Layout A (recommended):** DocumentRoot = `public/`, with `app/` one level up.
- **Layout B (flat / shared hosting):** everything in one folder, with `app/`
  next to `index.php` and assets served from `assets/` at the web root.

Root-relative links, redirects and asset URLs are rewritten to include the
subfolder base automatically, so the panel works at the domain root or under
`/anything` with no extra configuration.

---

## Permissions (flags)

The panel uses the same CSS flags as SimpleAdmin, so permissions aren't
duplicated:

| Flag | Allows in the panel |
|------|---------------------|
| `@css/root` | full access (implies all) |
| `@css/ban` | create / delete bans |
| `@css/unban` | lift bans |
| `@css/chat` | mute / gag / silence |
| `@css/generic` | warnings |
| `@css/rcon` | RCON commands |

Panel access is granted three ways:
- game admins in `sa_admins` (their flags decide what they can do),
- **Panel users** (root / admin / moderator, managed in the UI),
- `super_admins` in `config.php` (guaranteed root).

---

## Themes

Themes are **server-side** (stored in the database) so the whole community sees
the same look once a manager sets it. There are three kinds:

1. **Preset themes** — curated palettes (choose one + an accent color).
2. **Custom themes** — build your own full light/dark palette in Settings.
3. **Animated themes** — self-contained CSS files with constant motion.

### Adding an animated theme (drop-in folder)

Animated themes live in `assets/css/themes/*.css` and are auto-discovered — just
add a file, no code change. Each file declares its metadata in a header comment:

```css
/* @theme id:aurora-flow | name:Aurora Flow | accent:#2bd4b0 | tag:Animated */

[data-theme="aurora-flow"] { --accent:#16c79a; --accent-2:#22d3aa; }
[data-theme="aurora-flow"][data-mode="dark"]  { /* dark palette */ }
[data-theme="aurora-flow"][data-mode="light"] { /* light palette */ }
/* animate the shared .theme-fx layer that sits behind the app */
[data-theme="aurora-flow"] .theme-fx::before { /* ...keyframes... */ }
```

Bundled animated themes: **Aurora Flow, Neon Pulse, Cyber Grid, Starfield,
Sunset Drift, Ocean Waves, Holographic**. They appear automatically in
**Settings → Themes → Animated themes**.

---

## Internationalization

The UI ships in **English (default), Romanian, Russian, German**. Language files
are `app/Lang/<code>.php` returning a `key => text` map, with English as the
fallback for any missing key. The selected language is stored in a cookie and
switching it keeps you on the current page.

---

## Discord notifications

Every action (ban, unban, mute, warn, VIP add/extend/update/remove, Zenith
resets) can post a CSS-BANS style embed to a Discord webhook. The embed is:

- **Translatable** — all labels/phrases (Username, Steam Profile, Action, Group,
  Duration, Website, Admin, footer, action names, ...) use the panel's language
  system.
- **Customizable** — in **Settings → Discord** you can override any label with
  your own text (leave blank to use the translation).

Sending is fire-and-forget with a short timeout, so a slow/broken webhook never
blocks a panel action.

---

## Auto-update

The panel checks the GitHub repo
[`pandathebeasty/cs2_panel`](https://github.com/pandathebeasty/cs2_panel) for
newer **tags** and can update itself from the **Updates** page (root only).

- The current version + codename live in the `VERSION` file (e.g. `1.0.0 Ignition`).
- Releases are tagged `v1.0.0`, `v1.1.0`, ... each with a codename.
- The check is **cached** (a few hours) so it never slows down normal page loads;
  a pulsing dot appears in the sidebar when an update is available.
- Clicking **Update**:
  1. downloads the release zipball,
  2. takes a **full database backup** and a **zip of the current `app/`**,
  3. overlays the new `app/`, `database/`, and asset files
     (**never** touches `config/`, uploads, or `db_backups/`),
  4. runs any pending **DB migrations** (`database/migrations/*.sql`, tracked in
     `panel_migrations` so each runs once),
  5. bumps the `VERSION` file.

Requires the PHP `zip` and `curl` extensions and write access to the panel
folder. If write access isn't available, update the files manually and the
migration runner still applies pending schema changes.

### Migrations

Ship schema changes as `.sql` files in `database/migrations/`, named to sort in
order (e.g. `2026_02_01_add_vip_groups.sql`). Use `IF NOT EXISTS` / `IF EXISTS`
so re-running is harmless. They're applied automatically during an update.

---

## Integrations

- **CS2-SimpleAdmin** — the panel reads/writes `sa_bans`, `sa_mutes`, `sa_warns`,
  `sa_admins`, `sa_groups`, `sa_servers` (prefix configurable, default `sa_`).
- **VIPCore** — `vip_users` uses `account_id` = SteamID64 − 76561197960265728;
  the panel converts automatically, so you always enter a full SteamID64.
- **K4-Zenith** — table/column names vary between versions and are configurable
  (wizard / `config.php` / Settings). Queries are defensive: if a table or
  column is missing, that section is simply empty — no errors.

---

## Project structure

```
public/                 -> DocumentRoot (index.php, .htaccess, assets/)
  assets/
    css/app.css
    css/themes/*.css     -> drop-in animated themes
    js/app.js
    img/maps/*.png       -> optional map preview images
app/
  Core/                 -> Router, Database, Auth, Rcon, A2S, Discord, Settings,
                           Installer, Migrator, Updater, Version, Backup, Brand, ...
  Controllers/          -> route logic
  Models/               -> data access (SimpleAdmin, VIPCore, Zenith, panel)
  Views/                -> templates + layouts + partials
  Lang/                 -> en / ro / ru / de translation maps
config/                 -> config.php (generated by the wizard; git-ignored)
database/
  panel_schema.sql      -> panel tables
  migrations/*.sql      -> version-tracked schema changes
VERSION                 -> current version + codename
```

---

## Security notes

- PDO prepared statements everywhere (no SQL injection).
- CSRF tokens on all state-changing forms/requests.
- Steam OpenID login; sessions are HTTP-only, SameSite=Lax, `secure` on HTTPS.
- Destructive actions (delete-all, resets) require root + confirmation and take a
  DB backup first.
- The exception handler logs to `error.log` (viewable under **Logs → Error Log**)
  and never leaks stack traces when `app.debug` is off.
- `config/config.php` holds credentials and is git-ignored; the updater never
  overwrites it.

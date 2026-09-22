<div align="center">

# CS2 Panel

**A fast, modern web admin panel for Counter-Strike 2 servers.**

Moderation · Store (PayPal) · Contests · VIP · Stats — in plain PHP, no framework.

`PHP 8.1+` · `MySQL/MariaDB` · [CS2-SimpleAdmin](https://github.com/daffyyyy/CS2-SimpleAdmin) · [K4-Zenith](https://github.com/K4ryuu/K4-Zenith) · [VIPCore](https://github.com/partiusfabaa/cs2-VIPCore)

</div>

---

## ✨ Features

| Area | What you get |
|---|---|
| **Moderation** | Bans, mutes/gags, warnings — paginated, searchable, with Steam avatars. Per-row delete + root-only *delete all* (auto DB backup). |
| **Servers** | Live A2S status, current map (preview + player count), RCON console, `steam://connect` button. Dead servers never hang the UI. |
| **Store** 💳 | PayPal shop for VIP + admin packages. Cart, discounts, fees (pass or absorb), donations, order history, refunds, per-buyer email + Discord receipt. |
| **Contests** 🏆 | Rank/playtime giveaways that auto-award VIP to top players, announced on Discord. |
| **VIP** | Add/extend/edit/remove (VIPCore). Configurable groups, auto SteamID64 ↔ account_id. |
| **Stats** | K4-Zenith ranks & playtime leaderboards, per-player + global resets. |
| **Admins** | CRUD over game admins, admin groups, and separate **panel users**. |
| **Logs** | Action log (who/what/target/IP) + error log, both filterable. |

## 🎨 Look & feel

- **Server-side themes** — set once, applied for everyone on every device.
- **Presets, a custom builder, and drop-in animated themes** (`assets/css/themes/*.css`, auto-discovered).
- Light / Dark / System, collapsible sidebar, fully responsive, `prefers-reduced-motion` aware.
- **4 languages**: English · Română · Русский · Deutsch.

## 🔐 Roles

| Role | Can do |
|---|---|
| **Owner** | Everything, incl. panel users & destructive actions. |
| **Moderator** | Bans & mutes (warnings are view-only). |

Panel access = game admins (`sa_admins` flags) · panel users · `super_admins` in `config.php`.

---

## 🚀 Install

1. Upload the project. **DocumentRoot → `public/`** (or use the flat layout — auto-detected).
2. Ensure PHP can write `config/`, `db_backups/`, and `assets/img/`.
3. Open the site → complete the **`/install`** wizard (DB + prefix, site URL, root SteamID64, integrations, optional VIP groups & Discord).
4. Sign in with Steam. Everything else lives in **Settings**.

<details>
<summary><b>Nginx snippet</b></summary>

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
</details>

> **Requirements:** PHP 8.1+ (`pdo_mysql`, `curl`, `fileinfo`, `zip`), MySQL/MariaDB (same DB as SimpleAdmin), Apache/Nginx. Optional [Steam Web API key](https://steamcommunity.com/dev/apikey) for names + avatars.

---

## ⏱ Maintenance cron

Periodic work (finish contests, expire admin perks, auto-cancel unpaid orders) runs via one endpoint. Add the line from **Settings → General → Maintenance** to your host cron:

```cron
* * * * * curl -s "https://YOUR-SITE/cron?token=SECRET" >/dev/null 2>&1
```

Any interval works (5-min minimum on some hosts is fine). No cron? A throttled fallback runs it on page loads. Payments grant instantly via the PayPal webhook — they don't wait for cron.

---

## 🔄 Auto-update

Root can one-click update from the **Updates** page: backs up the DB + `app/`, overlays new files (never `config/`, uploads, or backups), runs pending migrations, and bumps `VERSION`. Cached check, so page loads stay fast.

**Migrations** — `database/migrations/NNN_name.sql` (numeric index, sorted), tracked in `panel_migrations`, run once. Always use `IF NOT EXISTS` / `IF EXISTS`.

---

## 🧩 Integrations

- **CS2-SimpleAdmin** — reads/writes `sa_*` tables (prefix configurable).
- **VIPCore** — `vip_users`, auto SteamID64 conversion.
- **K4-Zenith** — configurable table/column names; missing tables → section simply empty, no errors.

---

## 📁 Structure

```
public/            DocumentRoot (index.php, assets/)
  assets/css/themes/*.css   drop-in animated themes
  assets/img/maps/*.png     optional map previews
app/
  Core/            Router, DB, Auth, Rcon, A2S, Discord, Store, PayPal,
                   Maintenance, Migrator, Updater, Settings, Backup, ...
  Controllers/     route logic
  Models/          SimpleAdmin · VIPCore · Zenith · panel data
  Views/           templates + layouts + partials
  Lang/            en · ro · ru · de
config/            config.php (generated, git-ignored)
database/          panel_schema.sql + migrations/*.sql
VERSION            current version + codename
```

---

## 🛡 Security

PDO prepared statements · CSRF on all writes · Steam OpenID (HTTP-only, SameSite=Lax, Secure on HTTPS) · destructive actions need Owner + confirmation + DB backup · errors logged, never leaked · `config.php` git-ignored and never overwritten by the updater.

<div align="center">
<sub>Built with care for the CS2 community.</sub>
</div>

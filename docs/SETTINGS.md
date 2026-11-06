# Settings reference

Every option on the **Settings** page, what it does and its default value.

- Open it from the sidebar: **Settings** (staff with the *manage* permission). The **Updates**, **Health**, **Backups**, **Server Monitoring** and **Security** tabs are visible only to the owner (root).
- Press **Save** at the bottom of the page after changing anything. The *System* tabs (Backups, Server Monitoring, Security) have their own **Save** button.
- Settings are stored in the `panel_settings` table, so they survive updates. Values that also exist in `config/config.php` (site URL, timezone, language, integrations, databases) are written back to that file automatically.
- Every save is recorded in the action log and, if enabled, sent to Discord. Passwords and secrets are never shown in those messages.

## At a glance: what is on by default

| Feature | Default | Where to change it |
|---|---|---|
| CS2-SimpleAdmin (bans, mutes, warns, admins) | Always on | — |
| VIPCore | **On** (follows the wizard checkbox) | Integrations → VIPCore |
| Ranks & stats | **On**, plugin K4-Zenith (follows the wizard) | Integrations → Ranks & Stats |
| K4-System Missions / Achievements / Seasons | Off | Integrations → Ranks & Stats → Player Progress |
| Skins (WeaponPaints) | Off (follows the wizard) | Integrations → Skins |
| Chat Logger | Off | Integrations → Chat Logger |
| Store credits | Off | Integrations → Store Credits |
| Dedicated Database per integration | Off (all use the panel database) | each integration card |
| Appeals | **On** | Moderation |
| Reports | **On** | Moderation |
| Contests | **On** | Contests |
| Store (PayPal) | Off, Sandbox mode | Store |
| Donations / discount codes / checkout discount | Off | Store |
| Benefits page | Off | Benefits |
| Discord notifications | Off (on when a webhook is given in the wizard) | Moderation |
| Discord notification types | All **on** | Moderation → Notification Types |
| Server monitoring | **On**, every 2 minutes | Server Monitoring |
| Scheduled backups | Off | Backups |
| 2FA required for staff | Off (optional per account) | Security |
| Test mode | Off | General → Test Mode |

---

## Panel

### General

**Branding**

| Setting | Default | What it does |
|---|---|---|
| Name | `CS2 Panel` | Site name in the header, browser title, emails and Discord messages. |
| URL | from the wizard | Public address of the panel, without a trailing slash. Used for Steam login, links in emails/Discord and the cron command. Must match the real address exactly. |
| Logo | panel logo | Image in the header. **Reset to Default** removes yours. |
| Favicon | panel icon | Browser tab icon. **Reset to Default** restores the original. |
| Results per Page | `25` | Rows per page in every list (bans, VIP, ranks, …). |
| Timezone | from the wizard (`UTC`) | Used for every date shown in the panel and for scheduled tasks. |
| Language | from the wizard (`en`) | Default language for everyone: English, Română, Deutsch, Русский. Each visitor can still switch from the top bar. |

**Maintenance (cron)**

Runs periodic tasks: contests, expiring store perks, unpaid orders, server monitoring, scheduled backups, log cleanup and the weekly skins data refresh.

| Item | What it does |
|---|---|
| Cron Command | For hosts with separate Minute/Hour/… fields: leave them all on `*` and paste only this command. |
| Full Crontab Line | One line for `crontab -e`; it already runs every minute. |
| Cron token | Secret in the cron URL. **Regenerate** makes the old command stop working; update the cron afterwards. |

Without a cron job, the same tasks run on normal page visits, at most once a minute.

**Steam Web API**

| Setting | Default | What it does |
|---|---|---|
| Steam Web API Key | from the wizard | Shows player names and avatars. Get one at [steamcommunity.com/dev/apikey](https://steamcommunity.com/dev/apikey). |

**Login Session**

| Setting | Default | What it does |
|---|---|---|
| Stay logged in (days) | `30` | How long a login lasts. `0` = never log out. |

**Test Mode**

| Setting | Default | What it does |
|---|---|---|
| Enable Test Mode | Off | Shows test-only tools, like marking a store order as paid without a real PayPal charge. Keep it off in production. |

### Themes

| Section | What it does |
|---|---|
| Dashboard Layout | **Customize** opens the dashboard in edit mode to reorder or hide sections. **Reset** restores the default. Saved per device. |
| Preset Themes | Built-in colour themes. Each has a light and a dark version; the sun/moon button in the top bar switches between them. Default: `default`, dark. |
| Animated Themes | Themes with moving backgrounds. Animation stops for visitors who enabled *reduce motion* in their system. |
| Personal themes | **Install** a `.css` file. It is stored in `assets/css/themes/personal/` and kept across updates and reinstalls. The file must start with a header such as `/* @theme id:my-theme \| name:My Theme \| accent:#ff6a1a \| tag:Custom */`. |
| Custom Themes | Build a theme without CSS: pick a name and the background, surface, border, text and muted colours for both the dark and the light variant. |

The selected theme applies to every visitor. Each visitor can still switch light/dark from the top bar.

### Updates (owner only)

| Item | What it does |
|---|---|
| Check | Looks for a new release on GitHub now (otherwise checked automatically at most every 6 hours). |
| Update | Opens the update dialog. **Code** (code, interface, translations, database migrations) is always updated. **Maps** (`assets/img/maps`) and **Skins data** (`app/Data/weaponpaints`) are optional because they are large; tick them only when a release changes them. **3D models** (`assets/3d`, ~200 MB) is optional and only needed for the Skins 3D preview. |
| Rollback | Restores the code snapshot taken before the last update. |

Your `config/config.php`, uploads, personal themes, backups and all settings are kept on update.

### Health (owner only)

Read-only checks with a green / yellow / red status and a hint on how to fix each warning:

- **PHP:** version (8.2+ green, 8.1 yellow), required extensions (`pdo_mysql`, `curl`, `mbstring`, `openssl`, `zip`) and optional ones (`zlib`, `gd`, `sockets`), `memory_limit` (256M+ green), OPcache, `display_errors` (should be off).
- **Database:** MySQL/MariaDB version, database size, applied/pending migrations.
- **Files:** writable `db_backups`, `assets/img/uploads`, `app/Data` and root folder; free disk space; `error.log` size.
- **Security:** HTTPS, Steam API key, 2FA requirement.
- **Jobs:** last cron run (green if less than 15 minutes ago), last maintenance run, scheduled backups, panel version.

### Backups (owner only)

| Setting | Default | What it does |
|---|---|---|
| Enable automatic database backups | Off | Creates a database backup on a schedule (from the cron job, or on page visits when there is no cron). |
| Frequency | `daily` | `hourly`, `daily` or `weekly`. |
| Time | `03:00` | Time of day for daily/weekly backups (24h). |
| Weekday | Monday | Day for weekly backups. |
| Keep last | `7` | Number of automatic backups kept; older automatic ones are deleted. |
| Compress (.gz) | On | Smaller files. |
| Delete backups older than a set period | Off | Removes every backup (automatic, manual and code) older than the given days. The newest database backup and the files needed for rollback are always kept. |

The backup list lets you **Backup now**, download, restore or delete a backup. Backups are plain `.sql` files in `db_backups/` and work on shared hosting.

### Server Monitoring (owner only)

| Setting | Default | What it does |
|---|---|---|
| Enable server monitoring | **On** | Queries every server periodically and stores uptime history. |
| Check every (minutes) | `2` | Interval between checks (runs from the cron job). |
| Alert After Failed Checks | `3` | Consecutive failed checks before a server is marked offline. |
| Keep history (days) | `30` | Uptime history retention. |
| Discord webhook for alerts | empty | Optional. Sends a message when a server goes offline and when it comes back. |

### Security (owner only)

| Setting | Default | What it does |
|---|---|---|
| Require 2FA for all staff | Off | Every staff member must set up two-factor authentication. Enable 2FA on your own account first so you are not locked out. When off, 2FA stays optional (profile menu → Account security). |
| Keep audit log (days) | `0` | Deletes older action log entries automatically. `0` = keep forever. |

The staff list shows who has 2FA enabled and lets the owner reset it for a member who lost their device.

---

## Features & Integrations

### Contests

| Setting | Default | What it does |
|---|---|---|
| Enable Contests | **On** | VIP contests ranked by points or play time, with prizes per place. |
| Contest Discord Webhook | empty | Separate webhook used only for contest announcements (sent with `@everyone`). |
| Delete History After (days) | `90` | Finished contests older than this are removed. `0` = keep forever. |

### Moderation

**Moderation**

| Setting | Default | What it does |
|---|---|---|
| Enable Appeals | **On** | Players can appeal their own punishment. |
| Enable Reports | **On** | Visitors can report a player. Staff see every report; a player sees only their own. |

**Discord Notifications**

| Setting | Default | What it does |
|---|---|---|
| Enable Discord Notifications | Off (on if a webhook was given in the wizard) | Sends panel actions to Discord as rich embeds branded with your site name. |
| Webhook URL | from the wizard | Discord channel webhook. |
| Log All Actions | **On** | Also sends every other change as a compact audit message. |

**Notification Types**: all **on** by default. Untick what you do not want: Bans/Unbans, Mutes/Gags, Warnings, Appeals, Reports, VIP, Admins, Admin Groups, Resets, Stats Resets, Contests, Store Purchases, Orders, Donations, Servers, Panel Users, Settings Changes, Logins, Panel Updates, 2FA.

**Links**

| Setting | Default | What it does |
|---|---|---|
| Discord invite | empty | Invite code or full URL; adds a Discord link to the panel. |
| Forum URL | empty | Adds a Forum link to the panel. |

### Integrations

Each integration reads the plugin's own tables. Change table names only if you changed them in the plugin config.

**Dedicated Database** (in every card): off by default, so the integration uses the panel database. Tick it when the plugin stores its tables in another database or on another server, fill in Host, Port, Database, User and Password, then press **Test connection** (it also checks that the plugin tables exist). A saved password is kept when the field is left blank. CS2-SimpleAdmin on a dedicated database needs a server that accepts XA transactions.

**CS2-SimpleAdmin**

Always enabled; only the Dedicated Database option. The table prefix (default `sa_`) is set in the wizard / `config.php`.

**VIPCore**

| Setting | Default | What it does |
|---|---|---|
| Enable VIPCore | **On** | VIP list, add/extend/remove VIP, VIP prizes in contests and the store. |
| VIP Groups | empty | Your VIP group names, one per line. They appear as a dropdown when adding a VIP and in store packages. |
| Ignored VIP Groups | empty | Comma-separated groups hidden from the active VIP list. |
| VIP Time Mode | `3 - days` | Must match the VIPCore config: 0 seconds, 1 minutes, 2 hours, 3 days. |

**Ranks & Stats**

| Setting | Default | What it does |
|---|---|---|
| Enable ranks & stats | **On** | Ranks, stats and play time pages and the dashboard leaderboard. |
| Stats Plugin | `K4-Zenith` | Which plugin provides ranks, stats and play time: **K4-Zenith** or **K4-System**. Only one is active. |

*K4-Zenith*

| Setting | Default |
|---|---|
| Ranks Table | `zenith_ranks` |
| Stats Table | `zenith_stats` |
| Storage Table | `zenith_player_storage` |
| SteamID Column | `steam_id` |

*K4-System*

| Setting | Default | What it does |
|---|---|---|
| Table Prefix | empty | Prefix from the plugin database config (empty = none). |
| SteamID Column | `steam_id` | |
| Ranks / Stats / Times Table | `k4ranks` / `k4stats` / `k4times` | |
| Rank Style | `Competitive` | Rank icons: CS:GO skill groups (**Competitive**) or CS2 rating (**Premier**). |

*K4-System → Player Progress* (all off by default)

| Setting | Default | What it does |
|---|---|---|
| Missions | Off | Adds the **Missions** page under Statistics: each player's daily and weekly missions with progress, streak, server activity and top streaks. |
| Achievements | Off | Adds the **Achievements** page: every achievement with progress, tier, how many players own it, recent unlocks and top collectors. |
| Seasons | Off | Adds the **Seasons** page: current season with countdown, podium, standings and each player's season history. |
| missions.jsonc | Plugin default | Upload the file from your server if you changed the missions. **Reset to Default** goes back to the bundled file. The badge shows which file is used. |
| achievements.jsonc | Plugin default | Same as above, for achievements. |
| Missions timezone | `Europe/Istanbul` | Must match the plugin; used for the daily/weekly reset countdowns. |
| Easy / Medium / Hard / Weekly reward | `150` / `300` / `600` / `2500` | Points shown as mission rewards. Set them to the values in your plugin config. A mission with its own reward in `missions.jsonc` shows that value. |

A page appears only when it is ticked, K4-System is the selected plugin and its table exists. Uploaded files are stored in the database and kept on update. Mission and achievement names use the plugin's translations; names without a translation are generated from their key.

**Skins (WeaponPaints)**

| Setting | Default | What it does |
|---|---|---|
| Enable Skins | Off | Players build their WeaponPaints loadout from the panel. |
| Skins Table Prefix | `wp_` | The part before `player_skins` in the table names. |
| Check for Updates | — | Downloads the latest item lists and rarity colours now. Also refreshed automatically every week. |
| Enable 3D preview | Off | The skin editor shows the 3D model with the selected paint, and stickers can be placed and rotated on it. If WebGL2, the model or the paint texture is missing, the normal 2D image is shown. |
| Custom 3D sources | empty | Optional base URLs, one per line (at most 5). Each one must contain `models/{weapon}.glb` and `textures/{weapon}/{paint}.webp` (or `.png`, with an optional `{paint}_metal` map). Gloves use the defindex instead of the weapon name. |

The 3D files are looked up in this order: local models (`assets/3d/models`, installed with the **3D models** update part), the custom sources, then the public LielXD mirror (MIT). The paint textures are several GB, so they are not part of the update and come from the sources. Sticker placement is approximate; a sticker without a placement stays at the game's default position.

**Chat Logger**

| Setting | Default | What it does |
|---|---|---|
| Enable Chat Logs | Off | Read-only chat log viewer for the owner, under Administration. The plugin must have `MySql_Enable` on. |
| Chat Logger Table | `chat_logs` | Table created by the plugin; the panel never changes it. |

**Store Credits**

| Setting | Default | What it does |
|---|---|---|
| Enable store credits | Off | Sell in-game credits for the cs2-store plugin; credits are added to the player's balance after payment. |
| Credits Table Name | `store_players` | Must match the plugin config. |

### Store

**Store**

| Setting | Default | What it does |
|---|---|---|
| Enable Store | Off | Public store page with PayPal checkout. VIP/admin/credits are granted automatically after payment. |
| Currency | `EUR` | ISO code (`EUR`, `RON`, `USD`, …) supported by your PayPal account. |
| PayPal Mode | `Sandbox` | **Sandbox** to test with PayPal test accounts, **Live** for real payments. |

**PayPal API Credentials**: separate Client ID, Secret and Webhook ID for Sandbox and Live. Create an app at [developer.paypal.com](https://developer.paypal.com). The Webhook ID is optional; it verifies PayPal notifications sent to `https://your-panel/store/webhook`. Secrets are kept when the field is left blank.

**Processing Fee**

| Setting | Default | What it does |
|---|---|---|
| Fee percent (%) | `3.4` | PayPal percentage fee. |
| Fixed Fee | `0.35` | PayPal fixed fee per payment. |
| Buyer Pays Fee | **On** | On: the fee is added on top of the price. Off: you absorb it. |

**Orders**

| Setting | Default | What it does |
|---|---|---|
| Auto-Cancel Unpaid (min) | `10` | Unpaid orders older than this are cancelled. |
| Cart lifetime (minutes) | `10` | How long items stay in the cart. |

**Discounts**

| Setting | Default | What it does |
|---|---|---|
| Checkout Discount | Off | A percentage taken off every purchase, on top of package discounts and codes. |
| Checkout discount (%) | `0` | |
| Allow Discount Codes | Off | Buyers can enter a code in the cart. A code counts as used only once the order is paid. |
| New Discount Code | — | Code, percent, max uses (`0` = unlimited) and an optional valid from / until period. |

**Donations**

| Setting | Default | What it does |
|---|---|---|
| Enable Donations | Off | Visitors can donate a custom amount. No VIP/admin is granted. |
| Default Amount | `1.00` | Pre-filled amount. |
| Minimum Amount | `1.00` | |

**Confirmation Email**

| Setting | Default | What it does |
|---|---|---|
| From Address / From Name | empty | Sender of order confirmation emails (sent when the buyer enters an email). |
| SMTP Host | empty | Leave empty to use the server's built-in `mail()`. |
| SMTP Port | `587` | |
| Encryption | `tls` | `tls`, `ssl` or none. |
| SMTP Username / Password | empty | A saved password is kept when the field is left blank. |
| Test Email | — | Sends a test message. Save the settings first. |

**Packages**

| Field | What it does |
|---|---|
| Name, Extra details | Shown on the store page. |
| Server | Server the package applies to. |
| VIP Group, Days | VIP granted (`0` days = permanent). |
| Admin group, Admin Immunity, Admin days | Optional admin perk (`0` days = permanent). |
| Net Price | Your price; the buyer sees it with the fee when *Buyer Pays Fee* is on. |
| Discount (%), Discount from / until | Optional limited-time discount. |
| Grant Bonus Credits | Optional extra store credits. |
| Show "See benefits" button | Links the package to the Benefits page. |
| Enabled | Hide a package without deleting it. |

**Credit packages** (when Store Credits is enabled): name, credits, net price, discount, description, enabled.

### Benefits

| Setting | Default | What it does |
|---|---|---|
| Enable Benefits | Off | Public page listing package perks, grouped by category. |
| Benefits List | empty | Each row is one card: category, icon, benefit and description. Drag to reorder; empty rows are ignored. Icons: `vip-bronze`, `vip-silver`, `vip-gold`, `vip-diamond`, `admin`, `credits` (add `-premium` to a VIP name for the premium set), an emoji or an uploaded image. |

---

## Administration

### Admin

| Setting | Default | What it does |
|---|---|---|
| Ignored Admin Groups | empty | Comma-separated groups hidden from the Admins/Groups pages (e.g. VIPCore groups stored in the same table, `#vip/gold`). |
| In-game Admin Groups | — | Create, edit and delete CS2-SimpleAdmin groups: name, immunity and flags (SimpleAdmin, K4-Zenith, K4-System and custom flags). |

---

## Values kept in `config/config.php`

Written by the wizard and synced from Settings on every save, so the file always matches the panel:

| Key | Comes from |
|---|---|
| `db` | Wizard (main database). |
| `sa_prefix` | Wizard. |
| `steam.realm`, `steam.return_url`, `steam.api_key` | General → URL, Steam Web API Key. |
| `app` (name, timezone, language, per page) | General → Branding. |
| `stats`, `zenith`, `k4system` | Integrations → Ranks & Stats (including Missions, Achievements, Seasons and the missions timezone). |
| `vipcore`, `weaponpaints`, `chat_logger` | Integrations → VIPCore, Skins, Chat Logger. |
| `integration_db` | Dedicated Database of each integration. |
| `super_admins` | Wizard; edit the file to change it. |
| `rcon.timeout` | RCON timeout in seconds (default `3`); edit the file to change it. |

Everything else lives only in the database.

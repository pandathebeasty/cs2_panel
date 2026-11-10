<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\DevMode;
use App\Core\Discord;
use App\Core\Lang;
use App\Core\Settings;
use App\Models\ChatLogModel;

final class SettingsController extends Controller
{
    /**
     * The maintenance cron token, generated once and persisted. Secures the
     * public /cron endpoint the host's crontab calls (contests, admin-perk
     * expiry, stale-order cancellation — all periodic panel work). A fresh
     * random token is generated the first time it's needed.
     */
    private static function cronToken(): string
    {
        $token = trim((string) Settings::get('cron_token', ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            Settings::set('cron_token', $token);
        }
        return $token;
    }

    /** Current on/off state for each Discord notification category (default on). */
    private static function discordCategoryToggles(): array
    {
        $out = [];
        foreach (\App\Core\Discord::CATEGORIES as $id => $label) {
            $out[$id] = Settings::bool('discord_notify_' . $id, true);
        }
        return $out;
    }

    public function index(): void
    {
        Auth::requireManage();
        \cs2panel_trace('settings.index.begin');

        // Keep panel_settings complete: any key that has no row yet is written
        // with its built-in default, so the DB alone describes the whole panel.
        Settings::seedDefaults();
        \cs2panel_trace('settings.index.defaults-seeded');

        // The group editor is optional: a missing/unreadable sa_groups table
        // must not take the whole Settings page down.
        try {
            $adminGroups = \App\Models\GroupModel::all(true);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] settings admin groups load failed: ' . $e->getMessage());
            $adminGroups = [];
        }
        \cs2panel_trace('settings.index.groups-loaded:' . count($adminGroups));

        // Update info for the Updates tab (root only; cached check, no per-load hit).
        $update = ['current' => \App\Core\Version::current(), 'codename' => \App\Core\Version::codename(), 'latest' => null, 'hasUpdate' => false];
        if (Auth::isRoot()) {
            try {
                // Use the cached check so opening Settings never blocks on GitHub.
                // The "Check" button on the Updates tab forces a fresh lookup.
                $latest = \App\Core\Updater::latestCached();
                $update['latest'] = $latest;
                $update['hasUpdate'] = $latest !== null && \App\Core\Version::compare($latest['version'], \App\Core\Version::current()) > 0;
            } catch (\Throwable $e) {
                // ignore — keep defaults
            }
        }

        $system = null;
        if (Auth::isRoot()) {
            try {
                $system = SystemController::viewData();
            } catch (\Throwable $e) {
                error_log('[CS2Panel] system tabs failed: ' . $e->getMessage());
            }
        }

        \cs2panel_trace('settings.index.before-render');
        $this->view('settings/index', [
            'csrf' => $this->csrfToken(),
            'update' => $update,
            'system' => $system,
            's'    => [
                'site_name'       => Settings::get('site_name', 'CS2 Panel'),
                'theme_accent'    => Settings::get('theme_accent', '#e01e37'),
                'website_url'     => Settings::get('website_url', ''),
                'site_language'   => Settings::get('site_language', \App\Core\Config::get('app.locale', 'en')),
                'per_page'        => Settings::get('per_page', '25'),
                'timezone'        => Settings::get('timezone', \App\Core\Config::get('app.timezone', 'UTC')),
                'timezones'       => \DateTimeZone::listIdentifiers(),
                // Prefill from the Settings value, or fall back to the config.php
                // key so an existing config key is shown (and can be edited).
                'steam_api_key'   => \App\Core\Steam::apiKey(),
                'login_days'      => Settings::get('login_days', '30'),
                'test_mode'       => Settings::bool('test_mode', false),
                'vip_enabled'     => Settings::bool('vip_enabled', true),
                'vip_groups'      => Settings::get('vip_groups', ''),
                'vip_ignored_groups' => Settings::get('vip_ignored_groups', ''),
                // VIPCore "time mode" from the plugin config: 0 sec, 1 min, 2 h, 3 days.
                'vip_time_mode'   => (string) Settings::get('vip_time_mode', '3'),
                // In-game admin groups, editable straight from Settings.
                'admin_groups'         => $adminGroups,
                'admin_ignored_groups' => Settings::get('admin_ignored_groups', ''),
                'flag_groups'          => \App\Core\Flags::grouped(),
                'custom_flags'         => \App\Core\Flags::custom(),
                'zenith_enabled'  => Settings::bool('zenith_enabled', true),
                'stats_enabled'   => Settings::bool('stats_enabled', true),
                'appeals_enabled' => Settings::bool('appeals_enabled', true),
                'reports_enabled' => Settings::bool('reports_enabled', true),
                'contests_enabled' => Settings::bool('contests_enabled', true),
                'contest_webhook'  => Settings::get('contest_webhook', ''),
                'contest_retention_days' => Settings::get('contest_retention_days', '90'),
                'cron_token' => self::cronToken(),
                // Benefits page
                'benefits_enabled' => Settings::bool('benefits_enabled', false),
                'benefits_items'   => \App\Models\BenefitModel::all(),
                // Store (PayPal shop)
                'store_enabled'          => Settings::bool('store_enabled', false),
                'store_currency'         => Settings::get('store_currency', 'EUR'),
                'store_paypal_mode'      => Settings::get('store_paypal_mode', 'sandbox'),
                // Per-environment credentials (sandbox + live kept separately).
                'store_paypal_sandbox_client_id'  => \App\Core\Store::paypalClientId('sandbox'),
                'store_paypal_sandbox_secret'     => \App\Core\Store::paypalSecret('sandbox'),
                'store_paypal_sandbox_webhook_id' => \App\Core\Store::paypalWebhookId('sandbox'),
                'store_paypal_live_client_id'     => \App\Core\Store::paypalClientId('live'),
                'store_paypal_live_secret'        => \App\Core\Store::paypalSecret('live'),
                'store_paypal_live_webhook_id'    => \App\Core\Store::paypalWebhookId('live'),
                'store_fee_percent'      => Settings::get('store_fee_percent', '3.4'),
                'store_fee_fixed'        => Settings::get('store_fee_fixed', '0.35'),
                'store_pass_fee_to_buyer' => Settings::bool('store_pass_fee_to_buyer', true),
                'store_pending_cancel_minutes' => (string) \App\Core\Store::pendingCancelMinutes(),
                // Cart lifetime + discounts
                'cart_ttl_minutes'        => (string) \App\Core\Cart::ttlMinutes(),
                'store_codes_enabled'     => \App\Core\Store::discountCodesEnabled(),
                'store_checkout_discount_enabled' => Settings::bool('store_checkout_discount_enabled', false),
                'store_checkout_discount_percent' => Settings::get('store_checkout_discount_percent', '0'),
                'discount_codes'          => \App\Models\DiscountModel::all(),
                // Donations
                'store_donations_enabled' => Settings::bool('store_donations_enabled', false),
                'store_donation_default' => Settings::get('store_donation_default', '1.00'),
                'store_donation_min'     => Settings::get('store_donation_min', '1.00'),
                // Email (SMTP optional; falls back to PHP mail()).
                'mail_from'              => Settings::get('mail_from', ''),
                'mail_from_name'         => Settings::get('mail_from_name', ''),
                'mail_smtp_host'         => Settings::get('mail_smtp_host', ''),
                'mail_smtp_port'         => Settings::get('mail_smtp_port', '587'),
                'mail_smtp_user'         => Settings::get('mail_smtp_user', ''),
                'mail_smtp_pass'         => Settings::get('mail_smtp_pass', ''),
                'mail_smtp_secure'       => Settings::get('mail_smtp_secure', 'tls'),
                'store_packages'         => \App\Core\Store::packages(false),
                'store_credits_enabled'  => Settings::bool('store_credits_enabled', false),
                'store_players_table'    => Settings::get('store_players_table', 'store_players'),
                'store_credit_packages'  => \App\Core\Store::creditPackages(false),
                'store_vip_groups'       => \App\Models\VipModel::configuredGroups(),
                'store_servers'          => (\App\Models\VipModel::enabled() ? \App\Models\VipModel::servers() : []),
                'store_admin_groups'     => \App\Models\GroupModel::options(),
                'discord_enabled' => Settings::bool('discord_enabled', false),
                'discord_log_all' => Settings::bool('discord_log_all', true),
                'discord_notify'  => self::discordCategoryToggles(),
                'discord_webhook' => Settings::get('discord_webhook', ''),
                'discord_invite'  => Settings::get('discord_invite', ''),
                'forum_url'       => Settings::get('forum_url', ''),
                // Custom Discord embed label overrides (empty = use the translation).
                'logo_path'       => Settings::get('logo_path', ''),
                'favicon_path'    => Settings::get('favicon_path', ''),
                // Zenith table mapping (falls back to config values if not overridden).
                'zenith_ranks'    => Settings::get('zenith_ranks', \App\Core\Config::get('zenith.ranks_table', 'zenith_ranks')),
                'zenith_stats'    => Settings::get('zenith_stats', \App\Core\Config::get('zenith.stats_table', 'zenith_stats')),
                'zenith_storage'  => Settings::get('zenith_storage', \App\Core\Config::get('zenith.storage_table', 'zenith_player_storage')),
                'zenith_steam'    => Settings::get('zenith_steam', \App\Core\Config::get('zenith.steam_column', 'steam_id')),
                // Stats backend selection + K4-System table mapping.
                'stats_backend'   => \App\Models\StatsBackend::which(),
                'k4_prefix'       => Settings::get('k4_prefix', \App\Core\Config::get('k4system.prefix', '')),
                'k4_ranks_table'  => Settings::get('k4_ranks_table', \App\Core\Config::get('k4system.ranks_table', 'k4ranks')),
                'k4_stats_table'  => Settings::get('k4_stats_table', \App\Core\Config::get('k4system.stats_table', 'k4stats')),
                'k4_times_table'  => Settings::get('k4_times_table', \App\Core\Config::get('k4system.times_table', 'k4times')),
                'k4_steam'        => Settings::get('k4_steam', \App\Core\Config::get('k4system.steam_column', 'steam_id')),
                'k4_rank_type'    => Settings::get('k4_rank_type', \App\Core\Config::get('k4system.rank_type', 'competitive')),
                'k4x' => [
                    'missions'     => Settings::bool('k4_missions_enabled', false),
                    'achievements' => Settings::bool('k4_achievements_enabled', false),
                    'seasons'      => Settings::bool('k4_seasons_enabled', false),
                    'tz'           => (string) Settings::get('k4_missions_tz', \App\Models\K4ExtrasModel::DEFAULT_TIMEZONE),
                    'rewards'      => array_map(
                        static fn(string $d): int => (int) Settings::get('k4_reward_' . $d, (string) \App\Models\K4ExtrasModel::DEFAULT_REWARDS[$d]),
                        array_combine(array_keys(\App\Models\K4ExtrasModel::DEFAULT_REWARDS), array_keys(\App\Models\K4ExtrasModel::DEFAULT_REWARDS))
                    ),
                    'files'        => [
                        'missions'     => ['custom' => \App\Models\K4ExtrasModel::isCustom('missions'), 'count' => count(\App\Models\K4ExtrasModel::missions())],
                        'achievements' => ['custom' => \App\Models\K4ExtrasModel::isCustom('achievements'), 'count' => count(\App\Models\K4ExtrasModel::achievements())],
                    ],
                ],
                // WeaponPaints "Skins" module.
                'skins_enabled'   => Settings::bool('skins_enabled', false),
                'wp_prefix'       => Settings::get('wp_prefix', \App\Core\Config::get('weaponpaints.prefix', 'wp_')),
                'wp_3d_enabled'   => Settings::bool('wp_3d_enabled', false),
                'wp_3d_sources'   => (string) Settings::get('wp_3d_sources', ''),
                'wp_3d_local'     => \App\Core\Skins3D::hasLocalModels(),
                'wp_any_team'     => Settings::bool('wp_any_team', false),
                'chat_logs_enabled' => ChatLogModel::enabled(),
                'chat_logs_table'   => Settings::get('chat_logs_table', 'chat_logs'),
                'extdb'             => self::integrationDbView(),
            ],
            // Built-in preset themes shown in the Themes tab.
            // Each entry carries a full color set used to render a live mini preview.
            // [label, tag, bg, bg2, card, border, accent, text, mut]
            // The topbar changes mode without changing the selected preset.
            'presets' => [
                'default'   => ['label' => 'Default',    'tag' => 'Accent',  'bg' => '#0b0c11', 'bg2' => '#12131a', 'card' => '#15161f', 'border' => '#262838', 'accent' => '#e01e37', 'text' => '#e8eaf2', 'mut' => '#3a3a4a'],
                'matrix'    => ['label' => 'Matrix',     'tag' => 'Cyber',   'bg' => '#050a07', 'bg2' => '#0a130d', 'card' => '#0b160f', 'border' => '#17301f', 'accent' => '#21e065', 'text' => '#d6ffe4', 'mut' => '#2e5140'],
                'aurora'    => ['label' => 'Aurora',     'tag' => 'Calm',    'bg' => '#071013', 'bg2' => '#0c1a1f', 'card' => '#0e1e24', 'border' => '#1d3a44', 'accent' => '#17d3b0', 'text' => '#e2f7f5', 'mut' => '#2d5158'],
                'ember'     => ['label' => 'Ember',      'tag' => 'Warm',    'bg' => '#120c07', 'bg2' => '#1c130b', 'card' => '#1f150c', 'border' => '#3d2a17', 'accent' => '#ff8a1e', 'text' => '#fff1e0', 'mut' => '#5f4a30'],
                'crimson'   => ['label' => 'Crimson',    'tag' => 'Bold',    'bg' => '#0e0708', 'bg2' => '#160b0d', 'card' => '#180c0e', 'border' => '#3a1c20', 'accent' => '#ff2d3f', 'text' => '#ffe8ea', 'mut' => '#5c2e33'],
                'sakura'    => ['label' => 'Sakura',     'tag' => 'Light',   'bg' => '#d8ced3', 'bg2' => '#e4dde1', 'card' => '#ded6dc', 'border' => '#a99aa3', 'accent' => '#e5397f', 'text' => '#33242d', 'mut' => '#66535e'],
            ],
        ]);
    }

    public function save(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        \cs2panel_trace('settings.save.begin');
        $tMode = (string) $this->input('theme_mode', '');
        if ($tMode !== '' && !in_array($tMode, \App\Core\Themes::MODES, true)) {
            $this->flash('error', Lang::t('settings.theme_mode_invalid'));
            $this->redirect('/settings#themes');
        }
        $chatTable = $this->input('chat_logs_table', Settings::get('chat_logs_table', 'chat_logs'));
        if (!is_string($chatTable) || !ChatLogModel::isValidTableName(trim($chatTable))) {
            $this->flash('error', Lang::t('settings.chat_logs_invalid_table'));
            $this->redirect('/settings#integrations');
        }
        $extDb = $this->collectIntegrationDbInput();

        // Avoid a separate remote DB round-trip for every settings field.
                                                                                  
        Settings::beginBatch();

        Settings::set('site_name', trim((string) $this->input('site_name', 'CS2 Panel')) ?: 'CS2 Panel');
        Settings::set('theme_accent', trim((string) $this->input('theme_accent', '#e01e37')));

        // Theme selection (name/mode/custom) committed together with the main Save,
        // so it always persists server-side (shared across all devices).
        $tName = trim((string) $this->input('theme_name', ''));
        if ($tName !== '' && preg_match('/^(custom:.+|[a-z0-9\-]+)$/i', $tName)) {
            Settings::set('theme_name', $tName);
        }
        if ($tMode !== '') {
            Settings::set('theme_mode', $tMode);
        }
        $tCustom = (string) $this->input('custom_themes', '');
        if ($tCustom !== '') {
            $decoded = json_decode($tCustom, true);
            if (is_array($decoded)) {
                Settings::set('custom_themes', json_encode($decoded));
            }
        }
        Settings::set('website_url', trim((string) $this->input('website_url', '')));
        Settings::set('per_page', (string) max(5, min(200, (int) $this->input('per_page', 25))));
                                                                               
        $tz = trim((string) $this->input('timezone', ''));
        if ($tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            Settings::set('timezone', $tz);
        }
        // Site-wide language — owner only (it changes the language for everyone).
        if (\App\Core\Auth::isRoot()) {
            $lang = trim((string) $this->input('site_language', ''));
            if (isset(\App\Core\Lang::AVAILABLE[$lang])) {
                Settings::set('site_language', $lang);
            }
        }
        // Steam Web API key (overrides config.php).
        Settings::set('steam_api_key', trim((string) $this->input('steam_api_key', '')));
        // Login duration in days (0 = never expire). Clamp to a sane range.
        Settings::set('login_days', (string) max(0, min(3650, (int) $this->input('login_days', 30))));
        Settings::set('test_mode', $this->input('test_mode') ? '1' : '0');
        // Per-feature test toggles (checkbox posts only when checked).
        foreach (array_keys(\App\Core\Store::testFeatures()) as $tfId) {
            Settings::set('test_feat_' . $tfId, $this->input('test_feat_' . $tfId) ? '1' : '0');
        }

        Settings::set('vip_enabled', $this->input('vip_enabled') ? '1' : '0');
        // VIP groups the admin defines (one per line or comma-separated). Normalize
        // to one-per-line, trimmed, de-duplicated.
        $groups = preg_split('/[\r\n,]+/', (string) $this->input('vip_groups', '')) ?: [];
        $groups = array_values(array_unique(array_filter(array_map('trim', $groups), static fn($g) => $g !== '')));
        Settings::set('vip_groups', implode("\n", $groups));
        // Normalize the ignored-groups list: trim each entry, drop empties.
        $ignored = array_filter(array_map('trim', explode(',', (string) $this->input('vip_ignored_groups', ''))), static fn($g) => $g !== '');
        Settings::set('vip_ignored_groups', implode(', ', $ignored));
        // VIPCore stores durations in its configured unit; the store converts to days.
        $timeMode = (string) (int) $this->input('vip_time_mode', 3);
        Settings::set('vip_time_mode', in_array($timeMode, ['0', '1', '2', '3'], true) ? $timeMode : '3');
        // Admin groups hidden from the panel (e.g. VIPCore groups in sa_groups).
        $adminIgnored = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $this->input('admin_ignored_groups', '')) ?: []), static fn($g) => $g !== '');
        Settings::set('admin_ignored_groups', implode(', ', array_unique($adminIgnored)));
        try {
            $this->saveAdminGroups();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] admin groups save failed: ' . $e->getMessage());
            $this->flash('error', 'Admin groups could not be saved (see the error log).');
        }
        \cs2panel_trace('settings.save.admin-groups-done');
        // zenith_enabled is derived from the stats_backend radio below (the two
        // backends are mutually exclusive) — not from a standalone checkbox.
        Settings::set('appeals_enabled', $this->input('appeals_enabled') ? '1' : '0');
        Settings::set('reports_enabled', $this->input('reports_enabled') ? '1' : '0');
        // Benefits page: enable toggle + catalog are both saved via /benefits/save
        // (its own form on the Benefits tab), so nothing to persist here.

                   
        Settings::set('contests_enabled', $this->input('contests_enabled') ? '1' : '0');
        Settings::set('contest_webhook', trim((string) $this->input('contest_webhook', '')));
        Settings::set('contest_retention_days', (string) max(0, min(3650, (int) $this->input('contest_retention_days', 90))));
        // Regenerate the maintenance cron token on request (invalidates the old
        // cron URL). Written under the new general key.
        if ($this->input('regenerate_cron_token')) {
            Settings::set('cron_token', bin2hex(random_bytes(16)));
        }

                                        
        Settings::set('store_enabled', $this->input('store_enabled') ? '1' : '0');
        $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $this->input('store_currency', 'EUR')));
        Settings::set('store_currency', $cur !== '' ? substr($cur, 0, 8) : 'EUR');
        Settings::set('store_paypal_mode', $this->input('store_paypal_mode') === 'live' ? 'live' : 'sandbox');
        // Per-environment credentials: both sandbox and live are kept, so
        // switching mode never wipes the other set. Secrets stay saved when the
        // field is left blank (same behaviour as the SMTP password).
        Settings::set('store_paypal_sandbox_client_id', trim((string) $this->input('store_paypal_sandbox_client_id', '')));
        Settings::set('store_paypal_sandbox_webhook_id', trim((string) $this->input('store_paypal_sandbox_webhook_id', '')));
        $sbSecret = (string) $this->input('store_paypal_sandbox_secret', '');
        if ($sbSecret !== '') {
            Settings::set('store_paypal_sandbox_secret', trim($sbSecret));
        }
        Settings::set('store_paypal_live_client_id', trim((string) $this->input('store_paypal_live_client_id', '')));
        Settings::set('store_paypal_live_webhook_id', trim((string) $this->input('store_paypal_live_webhook_id', '')));
        $liveSecret = (string) $this->input('store_paypal_live_secret', '');
        if ($liveSecret !== '') {
            Settings::set('store_paypal_live_secret', trim($liveSecret));
        }
        // Fee: clamp percent to 0..99, fixed to a non-negative money string.
        $feePercent = (float) str_replace(',', '.', (string) $this->input('store_fee_percent', '3.4'));
        $feePercent = max(0.0, min(99.0, $feePercent));
        Settings::set('store_fee_percent', rtrim(rtrim(number_format($feePercent, 3, '.', ''), '0'), '.') ?: '0');
        Settings::set('store_fee_fixed', \App\Core\Store::formatCents(\App\Core\Store::toCents((string) $this->input('store_fee_fixed', '0.35'))));
        Settings::set('store_pass_fee_to_buyer', $this->input('store_pass_fee_to_buyer') ? '1' : '0');
        // Auto-cancel window for unpaid (pending) orders. Clamp 1..1440 minutes.
        Settings::set('store_pending_cancel_minutes', (string) max(1, min(1440, (int) $this->input('store_pending_cancel_minutes', 10))));
        // How long a cart line is held before it expires on its own.
        Settings::set('cart_ttl_minutes', (string) max(1, min(1440, (int) $this->input('cart_ttl_minutes', 10))));
        Settings::set('store_codes_enabled', $this->input('store_codes_enabled') ? '1' : '0');
        Settings::set('store_checkout_discount_enabled', $this->input('store_checkout_discount_enabled') ? '1' : '0');
        $coPct = max(0.0, min(100.0, (float) str_replace(',', '.', (string) $this->input('store_checkout_discount_percent', '0'))));
        Settings::set('store_checkout_discount_percent', rtrim(rtrim(number_format($coPct, 2, '.', ''), '0'), '.') ?: '0');
        // These write to their own tables; a schema problem there must not
        // abort the whole save.
        try {
            $this->saveDiscountCodes();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] discount codes save failed: ' . $e->getMessage());
            $this->flash('error', 'Discount codes could not be saved (see the error log).');
        }
        \cs2panel_trace('settings.save.discount-codes-done');
        Settings::set('store_packages', $this->normalizeStorePackages());
        // Store credits (cs2-store): enable, table name, and credit packages.
        Settings::set('store_credits_enabled', $this->input('store_credits_enabled') ? '1' : '0');
        $creditsTable = trim((string) $this->input('store_players_table', 'store_players'));
        Settings::set('store_players_table', $creditsTable !== '' ? mb_substr($creditsTable, 0, 64) : 'store_players');
        Settings::set('store_credit_packages', $this->normalizeCreditPackages());
        // Donations
        Settings::set('store_donations_enabled', $this->input('store_donations_enabled') ? '1' : '0');
        Settings::set('store_donation_default', \App\Core\Store::formatCents(\App\Core\Store::toCents((string) $this->input('store_donation_default', '1.00'))));
        Settings::set('store_donation_min', \App\Core\Store::formatCents(\App\Core\Store::toCents((string) $this->input('store_donation_min', '1.00'))));
        // Email / SMTP (optional). If no SMTP host, PHP mail() is used.
        Settings::set('mail_from', trim((string) $this->input('mail_from', '')));
        Settings::set('mail_from_name', trim((string) $this->input('mail_from_name', '')));
        Settings::set('mail_smtp_host', trim((string) $this->input('mail_smtp_host', '')));
        Settings::set('mail_smtp_port', (string) max(1, min(65535, (int) $this->input('mail_smtp_port', 587))));
        Settings::set('mail_smtp_user', trim((string) $this->input('mail_smtp_user', '')));
        // Keep the existing SMTP password if the field is left blank.
        $smtpPass = (string) $this->input('mail_smtp_pass', '');
        if ($smtpPass !== '') {
            Settings::set('mail_smtp_pass', $smtpPass);
        }
        $sec = (string) $this->input('mail_smtp_secure', 'tls');
        Settings::set('mail_smtp_secure', in_array($sec, ['', 'ssl', 'tls'], true) ? $sec : 'tls');
        Settings::set('discord_enabled', $this->input('discord_enabled') ? '1' : '0');
        Settings::set('discord_log_all', $this->input('discord_log_all') ? '1' : '0');
        // Per-category notification toggles (checkbox posts only when checked).
        foreach (array_keys(\App\Core\Discord::CATEGORIES) as $catId) {
            Settings::set('discord_notify_' . $catId, $this->input('discord_notify_' . $catId) ? '1' : '0');
        }
        Settings::set('discord_webhook', trim((string) $this->input('discord_webhook', '')));
        Settings::set('discord_invite', trim((string) $this->input('discord_invite', '')));
        Settings::set('forum_url', trim((string) $this->input('forum_url', '')));

        // Zenith table mapping overrides.
        Settings::set('zenith_ranks', trim((string) $this->input('zenith_ranks', 'zenith_ranks')) ?: 'zenith_ranks');
        Settings::set('zenith_stats', trim((string) $this->input('zenith_stats', 'zenith_stats')) ?: 'zenith_stats');
        Settings::set('zenith_storage', trim((string) $this->input('zenith_storage', 'zenith_player_storage')) ?: 'zenith_player_storage');
        Settings::set('zenith_steam', trim((string) $this->input('zenith_steam', 'steam_id')) ?: 'steam_id');

        // Stats backend (zenith | k4system) + K4-System table mapping.
        // The backend radio is the single source of truth: the two plugins are
        // mutually exclusive, so selecting one implicitly disables the other;
        // the stats_enabled switch turns the whole integration off.
        $backend = $this->input('stats_backend') === 'k4system' ? 'k4system' : 'zenith';
        $statsOn = (bool) $this->input('stats_enabled');
        Settings::set('stats_enabled', $statsOn ? '1' : '0');
        Settings::set('stats_backend', $backend);
        Settings::set('zenith_enabled', ($statsOn && $backend === 'zenith') ? '1' : '0');
        Settings::set('k4_prefix', trim((string) $this->input('k4_prefix', '')));
        Settings::set('k4_ranks_table', trim((string) $this->input('k4_ranks_table', 'k4ranks')) ?: 'k4ranks');
        Settings::set('k4_stats_table', trim((string) $this->input('k4_stats_table', 'k4stats')) ?: 'k4stats');
        Settings::set('k4_times_table', trim((string) $this->input('k4_times_table', 'k4times')) ?: 'k4times');
        Settings::set('k4_steam', trim((string) $this->input('k4_steam', 'steam_id')) ?: 'steam_id');
        Settings::set('k4_rank_type', $this->input('k4_rank_type') === 'premier' ? 'premier' : 'competitive');
        $this->saveK4Extras();

        // WeaponPaints "Skins" module (standalone toggle + configurable table prefix).
        Settings::set('skins_enabled', $this->input('skins_enabled') ? '1' : '0');
        Settings::set('wp_prefix', trim((string) $this->input('wp_prefix', 'wp_')) ?: 'wp_');
        Settings::set('wp_3d_enabled', $this->input('wp_3d_enabled') ? '1' : '0');
        Settings::set('wp_3d_sources', implode("\n", \App\Core\Skins3D::parseSources((string) $this->input('wp_3d_sources', ''))));
        Settings::set('wp_any_team', $this->input('wp_any_team') ? '1' : '0');
        Settings::set('chat_logs_enabled', $this->input('chat_logs_enabled') ? '1' : '0');
        Settings::set('chat_logs_table', trim($chatTable));
        foreach ($extDb as $key => $value) {
            Settings::set($key, $value);
        }

                                                                                               
        if ($this->input('reset_logo')) {
            $this->resetImage('logo_path');
        }
        if ($this->input('reset_favicon')) {
            $this->resetImage('favicon_path');
        }

        // Upload logo and favicon (optional). A new upload overrides a reset.
        $this->handleUpload('logo', 'logo_path', ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif']);
        $this->handleUpload('favicon', 'favicon_path', ['png', 'ico', 'svg']);

        // Work out exactly which settings changed (for the audit log/Discord),
        // BEFORE committing. Secrets are named but never have their value shown.
        \cs2panel_trace('settings.save.before-batch-commit');
        $changedKeys = Settings::changedInBatch();
        $detail = $this->describeChangedSettings($changedKeys);

        try {
            Settings::commitBatch();
            Settings::seedDefaults();
        } catch (\RuntimeException $e) {
            error_log('[CS2Panel] Settings save or configuration sync failed: ' . $e->getMessage());
            $this->flash('error', Lang::t('settings.config_sync_failed'));
            $this->redirect('/settings');
        }
        \cs2panel_trace('settings.save.settings-committed');

        \App\Core\Logger::log('settings.save', null, $detail);
        $this->flash('success', 'Settings saved.');

                                             
        $tab = preg_replace('/[^a-z]/', '', (string) $this->input('active_tab', ''));
        $this->redirect('/settings' . ($tab !== '' ? '#' . $tab : ''));
    }

    /**
     * Turn the list of changed setting keys into a short, readable summary for
     * the audit log / Discord "Details" field, e.g.
     * "Changed: Site name, PayPal mode, Packages". Falls back to a generic
     * message when nothing detectable changed.
     *
     * @param string[] $keys
     */
    private function describeChangedSettings(array $keys): string
    {
        if (!$keys) {
            return 'No changes.';
        }

        // Map raw setting keys to human labels. Discord per-category toggles and
        // secrets collapse into one label each so the summary stays compact.
        static $labels = [
            'site_name' => 'Site name', 'theme_accent' => 'Accent color',
            'theme_name' => 'Theme', 'theme_mode' => 'Theme mode', 'custom_themes' => 'Custom themes',
            'website_url' => 'Website URL', 'per_page' => 'Rows per page', 'timezone' => 'Timezone',
            'site_language' => 'Language',
            'steam_api_key' => 'Steam API key', 'login_days' => 'Login duration', 'test_mode' => 'Test mode',
            'vip_enabled' => 'VIP enabled', 'vip_groups' => 'VIP groups', 'vip_ignored_groups' => 'VIP ignored groups',
            'vip_time_mode' => 'VIP time mode', 'admin_ignored_groups' => 'Ignored admin groups',
            'custom_flags' => 'Custom flags',
            'stats_enabled' => 'Ranks & stats enabled', 'zenith_enabled' => 'Zenith enabled', 'zenith_ranks' => 'Zenith tables', 'zenith_stats' => 'Zenith tables',
            'appeals_enabled' => 'Appeals enabled', 'reports_enabled' => 'Reports enabled',
            'zenith_storage' => 'Zenith tables', 'zenith_steam' => 'Zenith tables',
            'stats_backend' => 'Stats plugin', 'k4_rank_type' => 'Rank style',
            'k4_prefix' => 'K4-System tables', 'k4_ranks_table' => 'K4-System tables', 'k4_stats_table' => 'K4-System tables',
            'k4_times_table' => 'K4-System tables', 'k4_steam' => 'K4-System tables',
            'k4_missions_enabled' => 'K4 missions', 'k4_achievements_enabled' => 'K4 achievements', 'k4_seasons_enabled' => 'K4 seasons',
            'k4_missions_def' => 'Missions file', 'k4_achievements_def' => 'Achievements file', 'k4_missions_tz' => 'Missions timezone',
            'k4_reward_easy' => 'Mission rewards', 'k4_reward_medium' => 'Mission rewards',
            'k4_reward_hard' => 'Mission rewards', 'k4_reward_weekly' => 'Mission rewards',
            'skins_enabled' => 'Skins enabled', 'wp_prefix' => 'Skins table prefix',
            'wp_3d_enabled' => 'Skins 3D preview', 'wp_3d_sources' => 'Skins 3D sources',
            'wp_any_team' => 'Skins on both teams',
            'chat_logs_enabled' => 'Chat logs enabled', 'chat_logs_table' => 'Chat Logger table',
            'benefits_enabled' => 'Benefits page enabled',
            'contests_enabled' => 'Contests enabled', 'contest_webhook' => 'Contest webhook',
            'contest_retention_days' => 'Contest retention', 'cron_token' => 'Cron token',
            'store_enabled' => 'Store enabled', 'store_currency' => 'Store currency',
            'store_paypal_mode' => 'PayPal mode',
            'store_paypal_sandbox_client_id' => 'PayPal sandbox keys', 'store_paypal_sandbox_secret' => 'PayPal sandbox keys',
            'store_paypal_sandbox_webhook_id' => 'PayPal sandbox keys',
            'store_paypal_live_client_id' => 'PayPal live keys', 'store_paypal_live_secret' => 'PayPal live keys',
            'store_paypal_live_webhook_id' => 'PayPal live keys',
            'store_fee_percent' => 'Store fees', 'store_fee_fixed' => 'Store fees', 'store_pass_fee_to_buyer' => 'Store fees',
            'store_pending_cancel_minutes' => 'Order auto-cancel',
            'cart_ttl_minutes' => 'Cart lifetime',
            'store_codes_enabled' => 'Discount codes',
            'store_checkout_discount_enabled' => 'Checkout discount',
            'store_checkout_discount_percent' => 'Checkout discount',
            'store_packages' => 'Packages',
            'store_credits_enabled' => 'Store credits', 'store_players_table' => 'Store credits table',
            'store_credit_packages' => 'Credit packages',
            'store_donations_enabled' => 'Donations', 'store_donation_default' => 'Donations', 'store_donation_min' => 'Donations',
            'mail_from' => 'Email (SMTP)', 'mail_from_name' => 'Email (SMTP)', 'mail_smtp_host' => 'Email (SMTP)',
            'mail_smtp_port' => 'Email (SMTP)', 'mail_smtp_user' => 'Email (SMTP)', 'mail_smtp_pass' => 'Email (SMTP)',
            'mail_smtp_secure' => 'Email (SMTP)',
            'discord_enabled' => 'Discord enabled', 'discord_log_all' => 'Discord log-all',
            'discord_webhook' => 'Discord webhook', 'discord_invite' => 'Discord invite', 'forum_url' => 'Forum URL',
            'logo_path' => 'Logo', 'favicon_path' => 'Favicon',
        ];

        // zenith_enabled is DERIVED from the stats_backend radio (the two
        // backends are mutually exclusive). When the backend itself changed,
        // naming "Zenith enabled" too is redundant and misleading, so drop it.
        $backendChanged = in_array('stats_backend', $keys, true);

        $out = [];
        foreach ($keys as $k) {
            if ($k === 'zenith_enabled' && ($backendChanged || in_array('stats_enabled', $keys, true))) {
                continue; // covered by the explicit backend label below
            }
            // Per-category Discord toggles -> single "Discord notifications" label.
            if (str_starts_with($k, 'discord_notify_')) {
                $label = 'Discord notifications';
            } elseif (preg_match('/^extdb_([a-z0-9]+)_/', $k, $em) && isset(\App\Core\IntegrationDb::KEYS[$em[1]])) {
                $label = 'Separate database: ' . \App\Core\IntegrationDb::KEYS[$em[1]];
            } elseif ($k === 'stats_backend') {
                // Spell out which backend was chosen so the log isn't ambiguous.
                $backend = \App\Models\StatsBackend::which();
                $label = 'Stats plugin: ' . ($backend === 'k4system' ? 'K4-System' : 'K4-Zenith');
            } elseif (isset($labels[$k])) {
                $label = $labels[$k];
            } else {
                // Fallback for any unmapped key: turn a raw snake_case key like
                // "store_credits_enabled" into a readable "Store Credits Enabled"
                // instead of showing the raw key in the audit/Discord message.
                $label = ucwords(str_replace('_', ' ', $k));
            }
            // De-duplicate collapsed labels while preserving order.
            if (!in_array($label, $out, true)) {
                $out[] = $label;
            }
        }

        return 'Changed: ' . implode(', ', $out);
    }

    /**
     * Apply the discount-code rows posted by the Settings editor: update the
     * existing ones, delete those marked for removal, add the optional new one.
     */
    private function saveDiscountCodes(): void
    {
        $ids     = (array) $this->input('dc_id', []);
        $codes   = (array) $this->input('dc_code', []);
        $percent = (array) $this->input('dc_percent', []);
        $maxUses = (array) $this->input('dc_max_uses', []);
        $fromDate = (array) $this->input('dc_from_date', []);
        $fromTime = (array) $this->input('dc_from_time', []);
        $toDate   = (array) $this->input('dc_to_date', []);
        $toTime   = (array) $this->input('dc_to_time', []);
        $enabled = (array) $this->input('dc_enabled', []);
        $remove  = (array) $this->input('dc_remove', []);

        foreach ($ids as $i => $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            if (!empty($remove[$i])) {
                \App\Models\DiscountModel::delete($id);
                continue;
            }
            \App\Models\DiscountModel::save(
                $id,
                (string) ($codes[$i] ?? ''),
                (float) str_replace(',', '.', (string) ($percent[$i] ?? '0')),
                (int) ($maxUses[$i] ?? 0),
                $this->combineDateTime((string) ($fromDate[$i] ?? ''), (string) ($fromTime[$i] ?? '')),
                $this->combineDateTime((string) ($toDate[$i] ?? ''), (string) ($toTime[$i] ?? '')),
                !empty($enabled[$i])
            );
        }

        $newCode = trim((string) $this->input('dc_new_code', ''));
        if ($newCode !== '') {
            \App\Models\DiscountModel::save(
                null,
                $newCode,
                (float) str_replace(',', '.', (string) $this->input('dc_new_percent', '0')),
                (int) $this->input('dc_new_max_uses', 0),
                $this->combineDateTime((string) $this->input('dc_new_from_date', ''), (string) $this->input('dc_new_from_time', '')),
                $this->combineDateTime((string) $this->input('dc_new_to_date', ''), (string) $this->input('dc_new_to_time', '')),
                true
            );
        }
    }

       
                                                                           
       
    private function saveAdminGroups(): void
    {
        $ids       = (array) $this->input('grp_id', []);
        $names     = (array) $this->input('grp_name', []);
        $immunity  = (array) $this->input('grp_immunity', []);
        $flags     = (array) $this->input('grp_flags', []);
        $originalNames = (array) $this->input('grp_original_name', []);
        $originalImmunity = (array) $this->input('grp_original_immunity', []);
        $originalFlags = (array) $this->input('grp_original_flags', []);

        foreach ($ids as $i => $gid) {
            $gid = (int) $gid;
            if ($gid <= 0) {
                continue;
            }
            $name = trim((string) ($names[$i] ?? ''));
            if ($name === '') {
                continue;
            }
            $immunityValue = (int) ($immunity[$i] ?? 0);
            $flagsValue = array_values(array_unique(array_filter(array_map('trim', (array) ($flags[$i] ?? [])))));
            $beforeFlags = json_decode((string) ($originalFlags[$i] ?? ''), true);
            if (isset($originalNames[$i], $originalImmunity[$i]) && is_array($beforeFlags)) {
                $beforeFlags = array_values(array_unique(array_filter(array_map('trim', $beforeFlags))));
                sort($beforeFlags);
                $compareFlags = $flagsValue;
                sort($compareFlags);
                if ($name === trim((string) $originalNames[$i])
                    && $immunityValue === (int) $originalImmunity[$i]
                    && $compareFlags === $beforeFlags) {
                    continue;
                }
            }
            \App\Models\GroupModel::update(
                $gid,
                $name,
                $immunityValue,
                $flagsValue,
                \App\Models\GroupModel::serverIds($gid)
            );
        }

        $newName = trim((string) $this->input('grp_new_name', ''));
        if ($newName !== '') {
            $id = \App\Models\GroupModel::create(
                $newName,
                (int) $this->input('grp_new_immunity', 0),
                (array) $this->input('grp_new_flags', []),
                []
            );
            \App\Core\Logger::log('group.create', (string) $id, $newName);
        }
    }

    /**
     * Build the store_packages JSON from the parallel form arrays posted by the
     * packages editor. Rows with an empty name or VIP group are dropped. Prices
     * are stored as integer cents (net = what the owner receives). Each package
     * gets a stable id (kept across edits, or generated for new rows).
     */
    private function normalizeStorePackages(): string
    {
        $names    = (array) $this->input('pkg_name', []);
        $groups   = (array) $this->input('pkg_group', []);
        $days     = (array) $this->input('pkg_days', []);
        $prices   = (array) $this->input('pkg_price', []);
        $descs    = (array) $this->input('pkg_desc', []);
        $servers  = (array) $this->input('pkg_server', []);
        $enabled  = (array) $this->input('pkg_enabled', []);
        $benefits = (array) $this->input('pkg_benefits', []);
        $bonusOn  = (array) $this->input('pkg_bonus_credits_on', []);
        $bonusCr  = (array) $this->input('pkg_bonus_credits', []);
        $ids      = (array) $this->input('pkg_id', []);
        $agroups  = (array) $this->input('pkg_admin_group', []);
        $aimmun   = (array) $this->input('pkg_admin_immunity', []);
        $adays    = (array) $this->input('pkg_admin_days', []);
        $discPct  = (array) $this->input('pkg_discount', []);
        $discFromDate = (array) $this->input('pkg_discount_from_date', []);
        $discFromTime = (array) $this->input('pkg_discount_from_time', []);
        $discToDate   = (array) $this->input('pkg_discount_to_date', []);
        $discToTime   = (array) $this->input('pkg_discount_to_time', []);

        // Map admin group id -> name for storing a readable label with the order.
        $groupNames = [];
        foreach (\App\Models\GroupModel::options() as $g) {
            $groupNames[(int) $g['id']] = (string) $g['name'];
        }

        $out = [];
        // Iterate the ACTUAL posted keys (the editor uses explicit indices like
        // pkg_name[3], and rows can be removed leaving gaps), so every field is
        // read at the same index. A checkbox only posts when checked, so
        // enabled[$i] simply won't be set for unchecked rows.
        foreach (array_keys($names) as $i) {
            $name  = trim((string) ($names[$i] ?? ''));
            $group = trim((string) ($groups[$i] ?? ''));
            $adminGroupId = (int) ($agroups[$i] ?? 0);
            // A package needs a name and at least one perk (VIP group or admin group).
            if ($name === '' || ($group === '' && $adminGroupId <= 0)) {
                continue;
            }
            $id = trim((string) ($ids[$i] ?? ''));
            if ($id === '') {
                $id = substr(preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), 0, 40)
                    . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            }
            $out[] = [
                'id'              => $id,
                'name'            => mb_substr($name, 0, 128),
                'vip_group'       => mb_substr($group, 0, 64),
                'days'            => max(0, (int) ($days[$i] ?? 0)),
                'admin_group_id'  => $adminGroupId > 0 ? $adminGroupId : 0,
                'admin_group_name'=> $adminGroupId > 0 ? mb_substr((string) ($groupNames[$adminGroupId] ?? ''), 0, 64) : '',
                'admin_immunity'  => max(0, (int) ($aimmun[$i] ?? 0)),
                'admin_days'      => max(0, (int) ($adays[$i] ?? 0)),
                'net_cents'       => \App\Core\Store::toCents((string) ($prices[$i] ?? '0')),
                'description'     => mb_substr(trim((string) ($descs[$i] ?? '')), 0, 255),
                'server_id'       => (trim((string) ($servers[$i] ?? 'all')) ?: 'all'),
                // Optional percentage discount active within an optional date range.
                'discount_percent'=> max(0.0, min(100.0, (float) str_replace(',', '.', (string) ($discPct[$i] ?? '0')))),
                'discount_from'   => $this->combineDateTime((string) ($discFromDate[$i] ?? ''), (string) ($discFromTime[$i] ?? '')),
                'discount_to'     => $this->combineDateTime((string) ($discToDate[$i] ?? ''), (string) ($discToTime[$i] ?? '')),
                // A checkbox posts its value only when checked; the editor sends
                // enabled[<i>] so we can tell which rows are on.
                'enabled'         => !empty($enabled[$i]),
                                                                             
                'benefits_button' => !empty($benefits[$i]),
                // Bonus store credits granted on top of VIP/admin, only when
                // the per-row "bonus credits" checkbox is ticked.
                'bonus_credits'   => !empty($bonusOn[$i]) ? max(0, (int) ($bonusCr[$i] ?? 0)) : 0,
            ];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * Build the store_credit_packages JSON from the parallel form arrays posted
     * by the credits packages editor. A row needs a name and credits > 0.
     * Prices are stored as integer cents (net = what the owner receives).
     */
    private function normalizeCreditPackages(): string
    {
        $names   = (array) $this->input('cpkg_name', []);
        $credits = (array) $this->input('cpkg_credits', []);
        $prices  = (array) $this->input('cpkg_price', []);
        $descs   = (array) $this->input('cpkg_desc', []);
        $enabled = (array) $this->input('cpkg_enabled', []);
        $ids     = (array) $this->input('cpkg_id', []);
        $discPct = (array) $this->input('cpkg_discount', []);
        $discFromDate = (array) $this->input('cpkg_discount_from_date', []);
        $discFromTime = (array) $this->input('cpkg_discount_from_time', []);
        $discToDate   = (array) $this->input('cpkg_discount_to_date', []);
        $discToTime   = (array) $this->input('cpkg_discount_to_time', []);

        $out = [];
        foreach (array_keys($names) as $i) {
            $name = trim((string) ($names[$i] ?? ''));
            $cr   = max(0, (int) ($credits[$i] ?? 0));
            if ($name === '' || $cr <= 0) {
                continue;
            }
            $id = trim((string) ($ids[$i] ?? ''));
            if ($id === '') {
                $id = 'cr-' . substr(preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), 0, 36)
                    . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            }
            $out[] = [
                'id'              => $id,
                'name'            => mb_substr($name, 0, 128),
                'credits'         => $cr,
                'net_cents'       => \App\Core\Store::toCents((string) ($prices[$i] ?? '0')),
                'description'     => mb_substr(trim((string) ($descs[$i] ?? '')), 0, 255),
                'discount_percent'=> max(0.0, min(100.0, (float) str_replace(',', '.', (string) ($discPct[$i] ?? '0')))),
                'discount_from'   => $this->combineDateTime((string) ($discFromDate[$i] ?? ''), (string) ($discFromTime[$i] ?? '')),
                'discount_to'     => $this->combineDateTime((string) ($discToDate[$i] ?? ''), (string) ($discToTime[$i] ?? '')),
                'enabled'         => !empty($enabled[$i]),
            ];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * Persist the global theme (name/mode/accent/custom) via AJAX.
     * Theme is a site-wide setting shared by everyone on every device.
     */
    public function theme(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $mode = (string) $this->input('mode', 'dark');
        if (!in_array($mode, \App\Core\Themes::MODES, true)) {
            $message = Lang::t('settings.theme_mode_invalid');
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
                $this->json(['ok' => false, 'error' => $message], 422);
            }
            $this->flash('error', $message);
            $this->redirect('/settings#themes');
        }
        $name = trim((string) $this->input('name', 'default')) ?: 'default';
        // Allow: default, a bundled preset/animated id, or custom:<name>.
        if (!preg_match('/^(custom:.+|[a-z0-9\-]+)$/i', $name)) {
            $name = 'default';
        }
        Settings::beginBatch();
        Settings::set('theme_name', $name);

        Settings::set('theme_mode', $mode);

        $accent = trim((string) $this->input('accent', ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            Settings::set('theme_accent', $accent);
        }

        // Optional custom-themes JSON blob (only overwrite when provided).
        $custom = (string) $this->input('custom', '');
        if ($custom !== '') {
            $decoded = json_decode($custom, true);
            if (is_array($decoded)) {
                // Re-encode to normalize/strip anything unexpected.
                Settings::set('custom_themes', json_encode($decoded));
            }
        }

        try {
            Settings::commitBatch();
        } catch (\RuntimeException $e) {
            error_log('[CS2Panel] Theme save or configuration sync failed: ' . $e->getMessage());
            $message = Lang::t('settings.config_sync_failed');
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
                $this->json(['ok' => false, 'error' => $message], 500);
            }
            $this->flash('error', $message);
            $this->redirect('/settings#themes');
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            $this->json(['ok' => true]);
        }
        $this->redirect('/settings#themes');
    }

    /**
     * Separate-database state per integration for the Settings view (the
     * password itself is never sent back, only whether one is stored).
     * @return array<string,array<string,mixed>>
     */
    private static function integrationDbView(): array
    {
        $out = [];
        foreach (\App\Core\IntegrationDb::KEYS as $k => $label) {
            $out[$k] = [
                'label'    => $label,
                'enabled'  => Settings::get("extdb_{$k}_enabled", '0') === '1',
                'active'   => Database::isSeparate($k),
                'host'     => (string) Settings::get("extdb_{$k}_host", ''),
                'port'     => (string) Settings::get("extdb_{$k}_port", '3306'),
                'name'     => (string) Settings::get("extdb_{$k}_name", ''),
                'user'     => (string) Settings::get("extdb_{$k}_user", ''),
                'has_pass' => (string) Settings::get("extdb_{$k}_pass", '') !== '',
                'tables'   => \App\Core\IntegrationDb::ownedTables($k),
            ];
        }
        return $out;
    }

    /**
     * Validated extdb_* values from the Settings form. A blank password keeps
     * the stored one. A newly enabled or changed connection is tested first so
     * a typo can't take an integration offline.
     * @return array<string,string>
     */
    private function collectIntegrationDbInput(): array
    {
        $out = [];
        foreach (\App\Core\IntegrationDb::KEYS as $k => $label) {
            $enabled = $this->input("extdb_{$k}_enabled") ? '1' : '0';
            $host = trim((string) $this->input("extdb_{$k}_host", ''));
            $port = (int) $this->input("extdb_{$k}_port", 3306);
            $name = trim((string) $this->input("extdb_{$k}_name", ''));
            $user = trim((string) $this->input("extdb_{$k}_user", ''));
            $pass = (string) $this->input("extdb_{$k}_pass", '');
            if ($pass === '') {
                $pass = (string) Settings::get("extdb_{$k}_pass", '');
            }
            $port = $port >= 1 && $port <= 65535 ? $port : 3306;

            if ($enabled === '1') {
                if ($host === '' || $name === '' || $user === ''
                    || !preg_match('/^[A-Za-z0-9.\-_:\[\]]{1,255}$/', $host)
                    || !preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $name)
                    || mb_strlen($user) > 80) {
                    $this->flash('error', Lang::t('settings.extdb_invalid', ['name' => $label]));
                    $this->redirect('/settings#integrations');
                }
                $changed = Settings::get("extdb_{$k}_enabled", '0') !== '1'
                    || Settings::get("extdb_{$k}_host", '') !== $host
                    || (string) Settings::get("extdb_{$k}_port", '3306') !== (string) $port
                    || Settings::get("extdb_{$k}_name", '') !== $name
                    || Settings::get("extdb_{$k}_user", '') !== $user
                    || Settings::get("extdb_{$k}_pass", '') !== $pass;
                if ($changed && !DevMode::enabled()) {
                    $test = Database::test(['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $pass]);
                    if (!$test['ok']) {
                        $this->flash('error', Lang::t('settings.extdb_connect_failed', ['name' => $label]) . ' ' . $test['error']);
                        $this->redirect('/settings#integrations');
                    }
                    // Appeals and Reports write to SimpleAdmin and the panel together:
                    // both servers must support two-phase commit so those writes stay atomic.
                    if (in_array($k, \App\Core\IntegrationDb::CROSS_WRITE_KEYS, true)
                        && (empty($test['xa']) || !Database::supportsXa(Database::connection()))) {
                        $this->flash('error', Lang::t('settings.extdb_xa_required', ['name' => $label]));
                        $this->redirect('/settings#integrations');
                    }
                }
            }
            $out["extdb_{$k}_enabled"] = $enabled;
            $out["extdb_{$k}_host"] = $host;
            $out["extdb_{$k}_port"] = (string) $port;
            $out["extdb_{$k}_name"] = $name;
            $out["extdb_{$k}_user"] = $user;
            $out["extdb_{$k}_pass"] = $pass;
        }
        return $out;
    }

    /** Test a separate integration database (Settings > Integrations). AJAX JSON. */
    public function testIntegrationDb(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $k = (string) $this->input('key', '');
        if (!isset(\App\Core\IntegrationDb::KEYS[$k])) {
            $this->json(['ok' => false, 'error' => 'Unknown integration.'], 422);
        }
        $host = trim((string) $this->input('host', ''));
        $name = trim((string) $this->input('name', ''));
        if (!preg_match('/^[A-Za-z0-9.\-_:\[\]]{1,255}$/', $host) || !preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $name)) {
            $this->json(['ok' => false, 'error' => Lang::t('settings.extdb_invalid', ['name' => \App\Core\IntegrationDb::KEYS[$k]])], 422);
        }
        $pass = (string) $this->input('pass', '');
        if ($pass === '') {
            $pass = (string) Settings::get("extdb_{$k}_pass", '');
        }
        $port = (int) $this->input('port', 3306);
        $result = Database::test([
            'host' => $host,
            'port' => $port >= 1 && $port <= 65535 ? $port : 3306,
            'name' => $name,
            'user' => trim((string) $this->input('user', '')),
            'password' => $pass,
        ], \App\Core\IntegrationDb::ownedTables($k)['tables']);
        $this->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Upload a personal theme CSS (Settings > Themes). AJAX JSON.
     */
    public function themeUpload(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $file = $_FILES['theme_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $this->json(['ok' => false, 'error' => Lang::t('themes.err_upload')], 422);
        }
        $result = \App\Core\Themes::installPersonal((string) $file['tmp_name'], (string) $file['name']);
        if (!$result['ok']) {
            $this->json(['ok' => false, 'error' => $result['message']], 422);
        }
        \App\Core\Logger::log('theme.upload', (string) $result['id'], 'Personal theme installed: ' . $result['id']);
        $this->flash('success', $result['message']);
        $this->json(['ok' => true, 'id' => $result['id'], 'message' => $result['message']]);
    }

    /** Delete a personal theme; falls back to Default when it was active. AJAX JSON. */
    public function themeDelete(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $id = (string) $this->input('id', '');
        if (!preg_match('/^[a-z0-9\-]+$/', $id) || !\App\Core\Themes::removePersonal($id)) {
            $this->json(['ok' => false, 'error' => Lang::t('themes.err_delete')], 422);
        }
        if (Settings::get('theme_name', 'default') === $id) {
            Settings::set('theme_name', 'default');
        }
        \App\Core\Logger::log('theme.delete', $id, 'Personal theme deleted: ' . $id);
        $this->flash('success', Lang::t('themes.deleted'));
        $this->json(['ok' => true]);
    }

    /**
     * Re-download the skin rarity-colour map (ByMykel/CSGO-API) on demand, from
     * the Settings "Check for updates" button next to Enable Skins. AJAX JSON.
     */
    public function skinsRarityRefresh(): void
    {
        // This is an AJAX (fetch) endpoint, so EVERY exit path must be JSON —
        // otherwise the browser follows a redirect / parses an HTML page and
        // the popup shows garbage. requireManage() would redirect to /login
        // (302 -> the fetch silently loads the login page as a 200 HTML blob)
        // or render a 403 HTML page. So check auth here and reply JSON instead.
        if (!Auth::check()) {
            $this->json(['ok' => false, 'count' => 0, 'rarity' => 0,
                'error' => 'Session expired — reload the page and sign in again.'], 401);
            return;
        }
        if (!Auth::canManage()) {
            $this->json(['ok' => false, 'count' => 0, 'rarity' => 0,
                'error' => 'You do not have permission to do this.'], 403);
            return;
        }

        // verifyCsrf() normally exits with plain text on a bad token, so check
        // it here and reply JSON too.
        $token = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
            $this->json(['ok' => false, 'count' => 0, 'rarity' => 0,
                'error' => 'Invalid CSRF token — reload the page.'], 419);
            return;
        }

        // refreshAll() downloads ~20+ files from GitHub/jsDelivr and decodes
        // several multi-MB JSONs (stickers/collectibles). The default 30s limit
        // and a low memory_limit can kill it mid-way, which the client then
        // sees as a dropped 500/"network". Give it generous room, like Backup.
        @set_time_limit(300);
        // Try to raise memory as high as the host allows. Some hosts cap
        // ini_set() silently (it ends up at ~60M), so request a lot; the code
        // in WeaponPaintsData is also written to stream to disk and free each
        // file so it survives a low cap.
        if ((string) ini_get('memory_limit') !== '-1') {
            @ini_set('memory_limit', '512M');
        }

        // A FATAL error (e.g. "Allowed memory size exhausted") is NOT a
        // \Throwable, so try/catch can't see it and the request 500s with a
        // blank HTML page. Register a shutdown handler that, if a fatal killed
        // us, still emits JSON with the real reason so the popup shows it.
        $sentJson = false;
        register_shutdown_function(static function () use (&$sentJson) {
            if ($sentJson) {
                return;
            }
            $e = error_get_last();
            if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                if (!headers_sent()) {
                    http_response_code(200);
                    header('Content-Type: application/json');
                }
                echo json_encode(['ok' => false, 'count' => 0, 'rarity' => 0,
                    'error' => 'Fatal: ' . $e['message']]);
            }
        });

        // Never let an unexpected throwable escape as an HTML 500: catch it and
        // report the message as JSON so the popup shows what actually failed.
        try {
            $result = \App\Core\WeaponPaintsData::refreshAll();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] skins refresh failed: ' . $e->getMessage());
            $sentJson = true;
            $this->json(['ok' => false, 'count' => 0, 'rarity' => 0,
                'error' => $e->getMessage()], 200);
            return;
        }
        $sentJson = true;

        // Stamp the weekly throttle so the cron doesn't immediately re-run.
        if (!empty($result['ok'])) {
            try { Settings::set('last_rarity_colors_refresh', (string) time()); } catch (\Throwable $e) {}
        }
        $this->json([
            'ok'      => !empty($result['ok']),
            'count'   => (int) ($result['updated'] ?? 0),
            'rarity'  => (int) ($result['rarity'] ?? 0),
            'error'   => (string) ($result['error'] ?? ''),
        ]);
    }

                                                         
    public function testDiscord(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        // Test the unsaved form webhook when supplied, otherwise the stored URL.
                                                                 
        $formWebhook = trim((string) $this->input('discord_webhook', ''));

        // Prefer the webhook typed in the form; otherwise use the saved one.
        $webhook = $formWebhook !== '' ? $formWebhook : (string) Settings::get('discord_webhook', '');

        if ($webhook === '') {
            $this->flash('error', 'Enter a Discord webhook URL first.');
            $this->redirect('/settings#discord');
        }
        if (!preg_match('#^https://(discord\.com|discordapp\.com|ptb\.discord\.com|canary\.discord\.com)/api/webhooks/#', $webhook)) {
            $this->flash('error', 'That does not look like a Discord webhook URL (must start with https://discord.com/api/webhooks/...).');
            $this->redirect('/settings#discord');
        }

        $result = Discord::sendToWebhook(
            $webhook,
            'Test Notification',
            'info',
            ['Status' => 'The webhook works!', 'Site' => Settings::get('site_name', 'CS2 Panel')],
            'Test from ' . Settings::get('site_name', 'CS2 Panel')
        );

        if ($result['ok']) {
            $this->flash('success', 'Test message sent to Discord.');
        } else {
            $this->flash('error', 'Discord test failed: ' . ($result['error'] ?: 'unknown error'));
        }
        $this->redirect('/settings#discord');
    }

    /**
     * Send a test email (uses SMTP if configured, else PHP mail()). AJAX JSON
     * so it doesn't submit the whole settings form.
     */
    public function testMail(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $to = trim((string) $this->input('to', ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->json(['ok' => false, 'message' => Lang::t('settings.mail_test_invalid')]);
        }

        $site = (string) Settings::get('site_name', 'CS2 Panel');
        $via = \App\Core\Mailer::smtpConfigured() ? 'SMTP' : 'mail()';
        $ok = \App\Core\Mailer::send(
            $to,
            Lang::t('settings.mail_test_subject', ['site' => $site]),
            Lang::t('settings.mail_test_body', ['site' => $site])
        );

        $this->json([
            'ok'      => $ok,
            'message' => $ok
                ? Lang::t('settings.mail_test_ok', ['via' => $via])
                : Lang::t('settings.mail_test_failed'),
        ]);
    }

    /**
     * Safe image upload (logo/favicon). Type + size validation,
     * randomized name, stored in assets/img/uploads/. Saves the
     * relative path in panel_settings under the $settingKey key.
     *
     * @param string   $field      the file input name in the form
     * @param string   $settingKey the panel_settings key where the path is stored
     * @param string[] $allowedExt allowed extensions
     */
    /**
     * Reset an image setting back to the built-in default: delete the uploaded
     * file (if it lives in our uploads folder) and clear the stored path so the
     * layout falls back to the bundled SVG.
     */
    private function resetImage(string $settingKey): void
    {
        $old = (string) Settings::get($settingKey, '');
        if ($old !== '' && str_starts_with($old, '/assets/img/uploads/')) {
            $oldPath = PUBLIC_PATH . $old;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
        Settings::set($settingKey, '');
    }

    /** K4-System missions / achievements / seasons pages and their definition files. */
    private function saveK4Extras(): void
    {
        foreach (['missions', 'achievements', 'seasons'] as $page) {
            Settings::set('k4_' . $page . '_enabled', $this->input('k4_' . $page . '_enabled') ? '1' : '0');
        }

        $tz = trim((string) $this->input('k4_missions_tz', ''));
        if ($tz === '' || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = \App\Models\K4ExtrasModel::DEFAULT_TIMEZONE;
        }
        Settings::set('k4_missions_tz', $tz);

        foreach (\App\Models\K4ExtrasModel::DEFAULT_REWARDS as $d => $def) {
            $v = $this->input('k4_reward_' . $d, '');
            Settings::set('k4_reward_' . $d, (string) (is_numeric($v) ? max(0, min(1000000, (int) $v)) : $def));
        }

        foreach (['missions', 'achievements'] as $kind) {
            $field = 'k4_' . $kind . '_file';
            $file = $_FILES[$field] ?? null;
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $norm = null;
                $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
                if ($file['error'] === UPLOAD_ERR_OK && in_array($ext, ['jsonc', 'json'], true)
                    && $file['size'] > 0 && $file['size'] <= 256 * 1024 && is_uploaded_file($file['tmp_name'])) {
                    $raw = \App\Models\K4ExtrasModel::parseJsonc((string) file_get_contents($file['tmp_name']));
                    if ($raw !== null) {
                        $normalise = $kind === 'missions' ? 'normaliseMissions' : 'normaliseAchievements';
                        $norm = \App\Models\K4ExtrasModel::$normalise($raw);
                        // Tolerate a single wrapping object, e.g. { "Missions": { ... } }.
                        if ($norm === null && count($raw) === 1 && is_array(reset($raw))) {
                            $norm = \App\Models\K4ExtrasModel::$normalise(reset($raw));
                        }
                    }
                }
                if ($norm === null) {
                    $this->flash('error', Lang::t('settings.k4x_file_invalid', ['file' => (string) ($file['name'] ?? $kind)]));
                } else {
                    Settings::set('k4_' . $kind . '_def', (string) json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                continue;
            }
            if ($this->input('k4_' . $kind . '_reset')) {
                Settings::set('k4_' . $kind . '_def', '');
            }
        }
    }

    private function handleUpload(string $field, string $settingKey, array $allowedExt): void
    {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return; // no file uploaded - keep the existing value
        }

        $file = $_FILES[$field];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', "Upload {$field} failed (code {$file['error']}).");
            return;
        }

        // 5 MB limit
        if ($file['size'] > 5 * 1024 * 1024) {
            $this->flash('error', "File {$field} exceeds 5 MB.");
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $this->flash('error', "Invalid extension for {$field}. Allowed: " . implode(', ', $allowedExt));
            return;
        }

        // Real MIME validation (not just the extension) for raster images
        $mime = @mime_content_type($file['tmp_name']) ?: '';
        $allowedMime = [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp',
            'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'text/plain',
        ];
        if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
            $this->flash('error', "File type not allowed for {$field} ({$mime}).");
            return;
        }

        $dir = PUBLIC_PATH . '/assets/img/uploads';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->flash('error', 'Could not create the upload folder.');
            return;
        }

        $safeName = $field . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->flash('error', "Could not save file {$field}.");
            return;
        }

                                                        
        $old = (string) Settings::get($settingKey, '');
        if ($old !== '' && str_starts_with($old, '/assets/img/uploads/')) {
            $oldPath = PUBLIC_PATH . $old;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        // relative path used in <img src> / <link href>
        Settings::set($settingKey, '/assets/img/uploads/' . $safeName);
    }
}

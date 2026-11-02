<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sends localized, structured notifications to Discord.
 *
 * Sending is "fire-and-forget" with a short timeout: if Discord does not respond,
 * the panel action is NOT blocked (no 500, no hang).
 */
final class Discord
{
    /** Colors per action type. */
    private const COLORS = [
        'ban'    => 0xE01E37,        
        'unban'  => 0x2ECC71,         
        'mute'   => 0xE67E22,              
        'unmute' => 0x2ECC71,
        'warn'   => 0xF1C40F, // galben
        'vip'    => 0x9B59B6,       
        'admin'  => 0x3498DB,            
        'reset'  => 0x95A5A6,       
        'info'   => 0x6C5CE7,
        'contest' => 0xF1C40F,                   
        'appeal'  => 0x1ABC9C,                         
        'report'  => 0xE74C3C,                                
    ];

    public static function enabled(): bool
    {
        return Settings::bool('discord_enabled', false)
            && Settings::get('discord_webhook', '') !== '';
    }

    /**
     * Notification categories the admin can toggle in Settings -> Discord.
     * Each maps to a setting key "discord_notify_<id>" (default on) and a label
     * lang key for the UI. Order here is the display order.
     * @var array<string,string> id => label lang key
     */
    public const CATEGORIES = [
        'bans'        => 'settings.notify_bans',
        'mutes'       => 'settings.notify_mutes',
        'warns'       => 'settings.notify_warns',
        'appeals'     => 'settings.notify_appeals',
        'reports'     => 'settings.notify_reports',
        'vip'         => 'settings.notify_vip',
        'admins'      => 'settings.notify_admins',
        'groups'      => 'settings.notify_groups',
        'resets'      => 'settings.notify_resets',
        'contests'    => 'settings.notify_contests',
        'store'       => 'settings.notify_store',
        'orders'      => 'settings.notify_orders',
        'donations'   => 'settings.notify_donations',
        'servers'     => 'settings.notify_servers',
        'panel_users' => 'settings.notify_panel_users',
        'settings'    => 'settings.notify_settings',
        'auth'        => 'settings.notify_auth',
        'updates'     => 'settings.notify_updates',
        'zenith'      => 'settings.notify_zenith',
    ];

    /**
     * Map an audit action key (e.g. "server.create") to a notification
     * category, so the per-category toggles also govern the audit mirror.
     */
    public static function categoryForAction(string $action): string
    {
        static $map = [
            'ban.' => 'bans', 'unban.' => 'bans',
            'mute.' => 'mutes', 'warn.' => 'warns',
            'appeal.' => 'appeals', 'report.' => 'reports',
            'vip.' => 'vip', 'admin.' => 'admins', 'group.' => 'groups',
            'contest.' => 'contests', 'store.' => 'store',
            'order.' => 'orders', 'donation.' => 'donations',
            'server.' => 'servers', 'panel_user.' => 'panel_users',
            'settings.' => 'settings', 'auth.' => 'auth', 'login' => 'auth',
            'update.' => 'updates', 'zenith.' => 'zenith',
        ];
        foreach ($map as $prefix => $category) {
            if (str_starts_with($action, $prefix)) {
                return $category;
            }
        }
        return '';
    }

    /** Whether a given notification category is enabled (default true). */
    public static function categoryEnabled(string $category): bool
    {
        if ($category === '' || !isset(self::CATEGORIES[$category])) {
            return true; // unknown -> don't suppress
        }
        return Settings::bool('discord_notify_' . $category, true);
    }

    /**
     * Map a notification (by its title key + color type) to a category id.
     * Kept simple and based on the stable title keys used by callers.
     */
    public static function categoryFor(string $titleKey, string $type): string
    {
        if (str_contains($titleKey, 'refund')) return 'orders';
        if (str_contains($titleKey, 'donation')) return 'donations';
        if (str_contains($titleKey, 'store') || str_contains($titleKey, 'purchase')) return 'store';
        if (str_contains($titleKey, 'appeal'))                      return 'appeals';
        if (str_contains($titleKey, 'report'))                      return 'reports';
        if (str_contains($titleKey, 'contest'))                     return 'contests';
        if (str_contains($titleKey, 'admin'))                       return 'admins';
        if (str_contains($titleKey, 'vip'))                         return 'vip';
        if (str_contains($titleKey, 'warn'))                        return 'warns';
        if (str_contains($titleKey, 'mute') || str_contains($titleKey, 'unmute')) return 'mutes';
        if (str_contains($titleKey, 'ban')  || str_contains($titleKey, 'unban'))  return 'bans';
        if (str_contains($titleKey, 'reset') || $type === 'reset')  return 'resets';
        return '';
    }

    /**
     * Action prefixes that already send a rich, dedicated embed via notify()
     * (or sendContest). The audit mirror skips these so they aren't posted
     * twice. Everything else (server/group/panel_user/settings/logs/edits) is
     * mirrored by the audit log when discord_log_all is on.
     * @var string[]
     */
    private const AUDIT_SKIP = [
        'ban.', 'mute.', 'warn.', 'vip.', 'admin.', 'contest.', 'store.',
        'appeal.', 'report.',
        'zenith.reset_rank', 'zenith.reset_stats', 'zenith.reset_playtime',
        'zenith.reset_all_ranks', 'zenith.reset_all_stats', 'zenith.reset_all_playtime',
    ];

    /**
     * Mirror a panel action to the main Discord webhook as a compact audit
     * embed. Called from Logger::log(). Best-effort and non-blocking. Skips
     * actions that already have their own rich notification (see AUDIT_SKIP)
     * to avoid duplicate messages, and only runs when discord_log_all is on.
     */
    public static function audit(string $action, ?string $target = null, ?string $details = null): void
    {
        if (!self::enabled() || !Settings::bool('discord_log_all', true)) {
            return;
        }
        foreach (self::AUDIT_SKIP as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return;
            }
        }
        if (!self::categoryEnabled(self::categoryForAction($action))) {
            return;
        }

        $webhook  = (string) Settings::get('discord_webhook', '');
        $siteName = (string) Settings::get('site_name', 'CS2 Panel');
        $admin    = self::actorName();

        $label = Logger::actionLabel($action);

        $fields = [
            ['name' => self::phrase('discord.action'), 'value' => self::escape($label), 'inline' => true],
            ['name' => self::phrase('discord.actor'),  'value' => self::escape($admin), 'inline' => true],
        ];
        if ($target !== null && $target !== '') {
            // Only call the field "Steam ID" when the target really is one. For
            // group/server/panel-user/etc. actions the target is a numeric row
            // id, so use a neutral "Target" label instead of mislabelling it.
            $isSteam = Steam::isValidSteamId64((string) $target);
            $labelKey = $isSteam ? 'discord.steam_id' : 'discord.target';
            $fields[] = ['name' => self::phrase($labelKey), 'value' => (string) $target, 'inline' => false];
        }
        if ($details !== null && $details !== '') {
            $fields[] = ['name' => self::phrase('discord.details'), 'value' => self::escape((string) $details), 'inline' => false];
        }

        $embed = [
            'title'     => $label,
            'color'     => self::COLORS['info'],
            'fields'    => $fields,
            'footer'    => ['text' => $siteName . ' • ' . self::phrase('portal.community')],
            'timestamp' => date('c'),
        ];
        self::send($webhook, ['username' => $siteName, 'embeds' => [$embed]], false);
    }

    /**
     * Resolve a piece of embed text. Callers pass a translation key
     * (e.g. "discord.act_ban", "discord.reason"); it is translated for the
     * current panel language. $params fill {n} placeholders. A key with no
     * translation is returned as-is so callers can also pass literal strings.
     */
    private static function phrase(string $keyOrText, array $params = []): string
    {
        $translated = Lang::t($keyOrText, $params);
        return $translated === $keyOrText ? $keyOrText : $translated;
    }

    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', '`', '*', '_', '~', '[', ']', '|', '>'],
            ['\\\\', '\\`', '\\*', '\\_', '\\~', '\\[', '\\]', '\\|', '\\>'],
            $value
        );
    }

    private static function formatIdentifier(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_:\[\]-]{1,64}$/D', $value) === 1
            ? '`' . $value . '`'
            : self::escape($value);
    }

    private static function actorName(array $target = []): string
    {
        $explicit = trim((string) ($target['actor'] ?? ''));
        if ($explicit !== '') return $explicit;
        $name = trim((string) (Auth::user()['name'] ?? ''));
        return $name !== '' ? $name : self::phrase('discord.actor_unknown');
    }

    private static function panelLink(string $category): ?string
    {
        $url = rtrim((string) Settings::get('website_url', ''), '/');
        if ($url === '') return null;
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            error_log('[CS2Panel] Discord: invalid panel URL; a navigation link was not included.');
            return null;
        }
        $routes = [
            'bans' => '/bans', 'mutes' => '/mutes', 'warns' => '/warns',
            'appeals' => '/appeals', 'reports' => '/reports', 'vip' => '/vip',
            'admins' => '/admins', 'groups' => '/groups', 'contests' => '/contests',
            'store' => '/store', 'orders' => '/orders', 'donations' => '/store',
            'servers' => '/servers', 'panel_users' => '/panel-users',
            'settings' => '/settings', 'updates' => '/settings#updates',
        ];
        return $url . ($routes[$category] ?? '');
    }

    /** Notify from an actual database snapshot, rather than request defaults. */
    public static function notifyRecord(string $title, string $action, string $type, ?array $record, array $extraFields = []): void
    {
        if (!self::enabled()) return;
        if ($record === null) {
            error_log('[CS2Panel] Discord: record snapshot unavailable for ' . $action . '; notification not sent.');
            return;
        }
        $fields = [];
        if (isset($record['id'])) $fields['discord.record_id'] = '#' . (int) $record['id'];
        if (!empty($record['server_name'])) $fields['common.server'] = (string) $record['server_name'];
        elseif (!empty($record['server_id'])) $fields['discord.server_id'] = (string) $record['server_id'];
        if (isset($record['type'])) $fields['discord.restriction_type'] = (string) $record['type'];
        if (isset($record['reason'])) $fields['discord.reason'] = (string) $record['reason'];
        if (isset($record['duration'])) {
            $duration = (int) $record['duration'];
            $fields['discord.duration'] = $duration === 0
                ? self::phrase('discord.permanent')
                : self::phrase('discord.minutes_n', [$duration]);
        }
        foreach (['created' => 'discord.created', 'ends' => 'discord.expires'] as $column => $label) {
            if (empty($record[$column])) continue;
            $timestamp = strtotime((string) $record[$column]);
            if ($timestamp === false) {
                error_log('[CS2Panel] Discord: invalid stored timestamp in ' . $column . '.');
                $fields[$label] = (string) $record[$column];
            } else {
                $fields[$label] = '<t:' . $timestamp . ':F>';
            }
        }
        if (isset($record['status'])) {
            $fields[str_contains($action, 'delete') ? 'discord.previous_status' : 'discord.record_status'] = (string) $record['status'];
        }
        self::notify($title, $action, $type, array_replace($fields, $extraFields), [
            'name' => (string) ($record['player_name'] ?? ''),
            'steamid' => (string) ($record['player_steamid'] ?? ''),
        ]);
    }

    public static function notifyVip(string $action, string $steamId, int $serverId, ?array $record, array $extraFields = []): void
    {
        if (!self::enabled()) return;
        if ($record === null) {
            error_log('[CS2Panel] Discord: VIP snapshot unavailable for ' . $action . '; notification not sent.');
            return;
        }
        $removed = $action === 'discord.act_vip_removed';
        $fields = [
            'discord.group' => (string) $record['group'],
            'discord.vip_server_id' => (string) $serverId,
            $removed ? 'discord.previous_expires' : 'discord.expires' => (int) $record['expires'] === 0
                ? self::phrase('discord.permanent')
                : '<t:' . (int) $record['expires'] . ':F>',
        ];
        self::notify('discord.vip_title', $action, $removed ? 'reset' : 'vip', array_replace($fields, $extraFields), [
            'name' => (string) $record['name'], 'steamid' => $steamId,
        ]);
    }

    /**
     * Generic notification in the "CSS-BANS" embed style.
     *
     * All text can be a translation key (resolved via {@see self::phrase()}) or a
     * literal string. Structural labels (Username, Steam ID, ...) are localised
     * automatically and can be overridden per-label from the Discord settings.
     *
     * @param string $title   e.g. 'discord.ban_title' or a literal title
     * @param string $action  e.g. 'discord.act_ban' or a literal action name
     * @param string $type    color key: ban|unban|mute|warn|vip|admin|reset|info
     * @param array  $fields  pairs labelKey => value (one row each)
     * @param array  $target  ['name'=>..., 'steamid'=>...] optional (player)
     */
    public static function notify(string $title, string $action, string $type, array $fields = [], array $target = []): void
    {
        if (!self::enabled()) {
            return;
        }
        // Per-category toggle: admins choose which notification types to send
        // (Settings -> Discord). Defaults to on for every category.
        if (!self::categoryEnabled(self::categoryFor($title, $type))) {
            return;
        }

        $webhook  = (string) Settings::get('discord_webhook', '');
        $siteName = (string) Settings::get('site_name', 'CS2 Panel');
        $admin    = self::actorName($target);

        $embedFields = [];

        // Player block (username + profile + steamid), like the reference image.
        $name = trim((string) ($target['name'] ?? ''));
        $steamId = trim((string) ($target['steamid'] ?? ''));
        if ($name !== '' || $steamId !== '') {
            $embedFields[] = ['name' => self::phrase('discord.username'), 'value' => self::escape($name !== '' ? $name : self::phrase('discord.unknown')), 'inline' => true];
        }
        if ($steamId !== '') {
            $embedFields[] = ['name' => self::phrase('discord.steam_id'), 'value' => self::formatIdentifier($steamId), 'inline' => true];
        }
        if (Steam::isValidSteamId64($steamId)) {
            $embedFields[] = [
                'name'   => self::phrase('discord.steam_profile'),
                'value'  => '[' . self::phrase('discord.view_profile') . '](' . Steam::profileUrl($steamId) . ')',
                'inline' => true,
            ];
        }

        foreach ($fields as $label => $value) {
            $text = (string) $value;
            $fullWidth = str_contains($text, "\n") || str_contains((string) $label, 'reason')
                || str_contains((string) $label, 'message') || str_contains((string) $label, 'reply')
                || str_contains((string) $label, 'details') || str_contains((string) $label, 'flags');
            $embedFields[] = [
                'name' => self::phrase((string) $label),
                'value' => $text === '' ? self::phrase('discord.unavailable')
                    : (preg_match('/^<t:\d+:[FR]>$/D', $text) ? $text : self::escape($text)),
                'inline' => !$fullWidth,
            ];
        }

        // Navigation and the known initiator, without guessing an administrator.
        $panelUrl = self::panelLink(self::categoryFor($title, $type));
        $websiteVal = $panelUrl !== null ? '[' . self::phrase('discord.view_panel') . "]({$panelUrl})" : self::escape($siteName);
        $embedFields[] = ['name' => self::phrase('discord.website'), 'value' => $websiteVal, 'inline' => true];
        $embedFields[] = ['name' => self::phrase('discord.actor'), 'value' => self::escape($admin), 'inline' => true];

        $embed = [
            'title'       => self::phrase($title),
            'description' => self::phrase($action),
            'color'       => self::COLORS[$type] ?? self::COLORS['info'],
            'fields'      => $embedFields,
            'footer'      => ['text' => $siteName . ' • ' . self::phrase('portal.community')],
            'timestamp'   => date('c'),
        ];
        if (!empty($target['test'])) $embed['title'] = self::phrase('discord.test_notification') . ' • ' . $embed['title'];
                                                                               
        if (!empty($target['avatar']) && filter_var($target['avatar'], FILTER_VALIDATE_URL)
            && in_array(parse_url((string) $target['avatar'], PHP_URL_SCHEME), ['http', 'https'], true)) {
            $embed['thumbnail'] = ['url' => (string) $target['avatar']];
        }
        if ($panelUrl !== null) $embed['url'] = $panelUrl;

        self::send($webhook, ['username' => $siteName, 'embeds' => [$embed]], false);
    }

    /**
     * Send a simple embed to a given webhook directly (without Settings).
     * Used at install time, when the settings tables were just created.
     */
    /**
     * Send a simple embed to a given webhook directly (without Settings).
     * @return array{ok:bool, status:int, error:string}
     */
    public static function sendToWebhook(string $webhook, string $title, string $type, array $fields, string $footer = ''): array
    {
        if ($webhook === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'No webhook URL.'];
        }
        $embedFields = [];
        foreach ($fields as $label => $value) {
            $embedFields[] = ['name' => $label, 'value' => ($value === '' ? '—' : (string) $value), 'inline' => false];
        }
        $embed = [
            'title'  => $title,
            'color'  => self::COLORS[$type] ?? self::COLORS['info'],
            'fields' => $embedFields,
            'footer' => ['text' => $footer !== '' ? $footer : ('Time: ' . date('Y-m-d H:i:s'))],
            'timestamp' => date('c'),
        ];
        return self::send($webhook, ['embeds' => [$embed]]);
    }

    /** Is a separate contest webhook configured? */
    public static function contestEnabled(): bool
    {
        return trim((string) Settings::get('contest_webhook', '')) !== '';
    }

    /**
     * Announce a contest event to the SEPARATE contest webhook, with an
     * @everyone mention. Used for both the "winners drawn" and "VIP granted"
     * announcements. Sends nothing (and returns a no-webhook result) when the
     * contest webhook is not configured.
     *
     * @param string $title    localised, descriptive title
     * @param string $subtitle short description line (embed body)
     * @param array<int,array{position:int,name:?string,steamid:string,points:int,prize?:string}> $winners
     * @param array<string,string> $meta   extra labelKey => value rows (group, duration, ...)
     * @param bool   $mention  prepend @everyone (default true)
     * @return array{ok:bool, status:int, error:string}
     */
    public static function sendContest(string $title, string $subtitle, array $winners, array $meta = [], bool $mention = true, bool $provisional = false): array
    {
        $webhook = trim((string) Settings::get('contest_webhook', ''));
        if ($webhook === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'No contest webhook configured.'];
        }

        $siteName = (string) Settings::get('site_name', 'CS2 Panel');
        $siteUrl  = (string) Settings::get('website_url', '');

        // Ranking value: points for a rank contest, formatted play time for a
        // play-time contest (the metric travels with each winner row).
        $scoreLabel = static function (array $w): string {
            if ((string) ($w['metric'] ?? 'rank') === 'playtime') {
                $totalMinutes = max(0, (int) ($w['points'] ?? 0));
                $hours = intdiv($totalMinutes, 60);
                $mins  = $totalMinutes % 60;
                return self::phrase('nav.playtime') . ': **' . $hours . 'h ' . $mins . 'm**';
            }
            return self::phrase('dash.points') . ': **' . number_format((int) ($w['points'] ?? 0)) . '**';
        };

        $embedFields = [];
        foreach ($meta as $label => $value) {
            if ((string) $value === '') {
                continue;
            }
            // Multi-line values (e.g. the per-place prize list) render full-width.
            $inline = strpos((string) $value, "\n") === false;
            $embedFields[] = ['name' => self::phrase((string) $label), 'value' => (string) $value, 'inline' => $inline];
        }

        // Compact summary block: "Winners:\n1st place: name (points) — prize".
        // While the contest is still running these are only the current
        // standings, so the heading says so.
        if ($winners) {
            $lines = [];
            $pos = 0;
            foreach ($winners as $w) {
                $pos++;
                $rank    = (int) ($w['position'] ?? $pos);
                $name    = trim((string) ($w['name'] ?? '')) !== '' ? (string) $w['name'] : self::phrase('discord.unknown');
                $prize   = trim((string) ($w['prize'] ?? ''));
                $place   = self::phrase('discord.contest_place', [$rank]);
                $line    = '**' . $place . ':** ' . self::escape($name) . ' — ' . $scoreLabel($w);
                if ($prize !== '') {
                    $line .= ' — ' . self::escape($prize);
                }
                $lines[] = $line;
            }
            $embedFields[] = [
                'name'   => self::phrase($provisional ? 'discord.contest_standings_are' : 'discord.contest_winners_are'),
                'value'  => implode("\n", $lines),
                'inline' => false,
            ];
        }

        // Detailed field per winner: profile link + SteamID + score + prize.
        $pos = 0;
        foreach ($winners as $w) {
            $pos++;
            $rank    = (int) ($w['position'] ?? $pos);
            $name    = trim((string) ($w['name'] ?? '')) !== '' ? (string) $w['name'] : self::phrase('discord.unknown');
            $steamId = (string) ($w['steamid'] ?? '');
            $prize   = trim((string) ($w['prize'] ?? ''));
            $line = self::phrase('discord.steam_id') . ': ' . self::formatIdentifier($steamId)
                  . "\n" . $scoreLabel($w);
            if (Steam::isValidSteamId64($steamId)) {
                $line = '[' . self::phrase('discord.view_profile') . '](' . Steam::profileUrl($steamId) . ")\n" . $line;
            }
            if ($prize !== '') {
                $line .= "\n" . self::phrase('contests.prize') . ': **' . self::escape($prize) . '**';
            }
            if (!$provisional && array_key_exists('vip_granted', $w)) {
                $line .= "\n" . self::phrase('discord.vip_grant') . ': ' . self::phrase($w['vip_granted'] ? 'discord.granted' : 'discord.not_granted');
            }
            if (!$provisional && array_key_exists('credits_granted', $w)) {
                $line .= "\n" . self::phrase('discord.credits_grant') . ': ' . self::phrase($w['credits_granted'] ? 'discord.granted' : 'discord.not_granted');
            }
            $embedFields[] = [
                'name'   => '#' . $rank . ' — ' . self::escape($name),
                'value'  => $line,
                'inline' => false,
            ];
        }

        $websiteVal = $siteUrl !== '' ? '[' . self::phrase('discord.view_panel') . "]({$siteUrl})" : $siteName;
        $embedFields[] = ['name' => self::phrase('discord.website'), 'value' => $websiteVal, 'inline' => true];

        $embed = [
            'title'       => $title,
            'description' => $subtitle,
            'color'       => self::COLORS['contest'],
            'fields'      => $embedFields,
            'footer'      => ['text' => self::phrase('discord.footer', [$siteName, date('Y-m-d H:i:s')])],
            'timestamp'   => date('c'),
        ];

        $payload = ['username' => $siteName, 'embeds' => [$embed]];
        if ($mention) {
            $payload['content'] = '@everyone';
            $payload['allowed_mentions'] = ['parse' => ['everyone']];
        }
        return self::send($webhook, $payload);
    }



    private static function textUnits(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function clip(string $text, int $limit, bool &$trimmed): string
    {
        if (self::textUnits($text) <= $limit) return $text;
        $trimmed = true;
        if ($limit < 2) return $limit > 0 ? '…' : '';
        $low = 0;
        $high = mb_strlen($text, 'UTF-8');
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if (self::textUnits(mb_substr($text, 0, $middle, 'UTF-8')) <= $limit - 1) $low = $middle;
            else $high = $middle - 1;
        }
        return mb_substr($text, 0, $low, 'UTF-8') . '…';
    }

    private static function normalizePayload(array $payload): array
    {
        $trimmed = false;
        $payload['allowed_mentions'] ??= ['parse' => []];
        if (isset($payload['username'])) $payload['username'] = self::clip((string) $payload['username'], 80, $trimmed);
        if (isset($payload['content'])) $payload['content'] = self::clip((string) $payload['content'], 2000, $trimmed);
        $shortenedNote = ' • ' . self::phrase('discord.more_details');
        $budget = 6000 - self::textUnits($shortenedNote);
        $embeds = [];
        foreach (array_slice($payload['embeds'] ?? [], 0, 10) as $embed) {
            if ($budget < 1) {
                $trimmed = true;
                break;
            }
            foreach (['title' => 256, 'description' => 4096] as $key => $limit) {
                if (!isset($embed[$key])) continue;
                $embed[$key] = self::clip((string) $embed[$key], min($limit, $budget), $trimmed);
                $budget -= self::textUnits($embed[$key]);
            }
            foreach (['author' => ['name', 256], 'footer' => ['text', 2048]] as $key => [$textKey, $limit]) {
                if (!isset($embed[$key][$textKey])) continue;
                $embed[$key][$textKey] = self::clip((string) $embed[$key][$textKey], min($limit, $budget), $trimmed);
                $budget -= self::textUnits($embed[$key][$textKey]);
            }
            $fields = [];
            $originalFields = $embed['fields'] ?? [];
            foreach ($originalFields as $field) {
                if (count($fields) >= 25 || $budget < 3) {
                    $trimmed = true;
                    break;
                }
                $field['name'] = self::clip(trim((string) ($field['name'] ?? '')) ?: '—', min(256, $budget - 1), $trimmed);
                $budget -= self::textUnits($field['name']);
                $field['value'] = self::clip(trim((string) ($field['value'] ?? '')) ?: '—', min(1024, $budget), $trimmed);
                $budget -= self::textUnits($field['value']);
                $field['inline'] = (bool) ($field['inline'] ?? false);
                $fields[] = $field;
            }
            if ($fields) $embed['fields'] = $fields;
            else unset($embed['fields']);
            $embeds[] = $embed;
        }
        if (count($embeds) < count($payload['embeds'] ?? [])) $trimmed = true;
        if ($trimmed && $embeds) {
            $last = array_key_last($embeds);
            $footer = (string) ($embeds[$last]['footer']['text'] ?? '');
            $embeds[$last]['footer']['text'] = self::clip($footer, 2048 - self::textUnits($shortenedNote), $trimmed) . $shortenedNote;
        }
        $payload['embeds'] = $embeds;
        if ($trimmed) error_log('[CS2Panel] Discord: message text shortened to fit Discord limits; complete details remain in the panel.');
        return $payload;
    }

    /** @return array{ok:bool, status:int, error:string} */
    private static function send(string $webhook, array $payload, bool $wait = true): array
    {
        try {
            $json = json_encode(self::normalizePayload($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            error_log('[CS2Panel] Discord: message JSON encoding failed: ' . $e->getMessage());
            return ['ok' => false, 'status' => 0, 'error' => 'Discord message could not be encoded.'];
        }
        // Ordinary notifications use the existing shorter timeout; tests and
        // installation may wait longer. Both paths report the actual result.
        if (!$wait) {
            return self::sendAsync($webhook, $json);
        }

        if (!function_exists('curl_init')) {
            error_log('[CS2Panel] Discord: cURL extension not available.');
            return ['ok' => false, 'status' => 0, 'error' => 'cURL is not installed on the server.'];
        }

        $ch = curl_init($webhook);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
        ];
        // Use an explicit CA bundle if one is configured (fixes SSL on some hosts).
        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if ($ca && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            error_log('[CS2Panel] Discord webhook error: ' . $err);
            return ['ok' => false, 'status' => 0, 'error' => $err];
        }
        // Discord returns 204 (No Content) on success.
        $ok = $status >= 200 && $status < 300;
        if (!$ok) {
            error_log('[CS2Panel] Discord webhook HTTP ' . $status . ': ' . substr((string) $body, 0, 300));
        }
        return ['ok' => $ok, 'status' => $status, 'error' => $ok ? '' : ('Discord returned HTTP ' . $status)];
    }

    /**
     * Best-effort POST that still DELIVERS but keeps the wait short so a slow
     * Discord can't stall the page for long. The previous socket "fire and
     * forget" closed the TLS stream before the write flushed, which silently
     * dropped the message — so notifications never arrived. We use a real curl
     * request with a modest timeout instead: a healthy webhook replies in
     * ~100-300ms, and the worst case is capped well below the old 6s.
     */
    private static function sendAsync(string $webhook, string $json): array
    {
        if (!function_exists('curl_init')) {
            error_log('[CS2Panel] Discord: cURL extension not available.');
            return ['ok' => false, 'status' => 0, 'error' => 'cURL is not installed on the server.'];
        }
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if ($ca && is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            error_log('[CS2Panel] Discord async webhook error: ' . $err);
        } elseif ($status < 200 || $status >= 300) {
            error_log('[CS2Panel] Discord async webhook HTTP ' . $status . ': ' . substr((string) $body, 0, 300));
        }
        $ok = $err === '' && $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $err !== '' ? 0 : $status, 'error' => $err !== '' ? $err : ($ok ? '' : 'Discord returned HTTP ' . $status)];
    }
}

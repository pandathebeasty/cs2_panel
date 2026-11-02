<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Canonical list of CounterStrikeSharp / plugin admin flags.
 *
 * Single source of truth so the Admins page and the Admin Groups page always
 * offer the same, complete set of selectable flags. Admins may also type their
 * own custom flag (e.g. @myplugin/feature) — any flag already stored on a
 * group/admin that isn't in this list is still shown and kept.
 *
 * References (summarised for compliance):
 *  - CounterStrikeSharp admin framework standard @css/* flags
 *  - CS2-SimpleAdmin command permissions
 *  - K4-Zenith (@zenith/*, @zenith-bans/*) and K4-System (@k4/*)
 */
final class Flags
{
    /**
     * Flags grouped by the plugin/system they belong to.
     * Structure: [ groupLabel => [ flag => humanLabel ] ]
     *
     * Groups belonging to an optional integration (K4-Zenith, VIPCore) are
     * omitted when that integration is disabled in Settings.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $groups = [
            'Admin Flags' => [
                '@css/reservation' => 'Reserved Slot',
                '@css/generic'     => 'Generic',
                '@css/chat'        => 'Chat',
                '@css/kick'        => 'Kick',
                '@css/ban'         => 'Ban',
                '@css/permban'     => 'Perm Ban',
                '@css/unban'       => 'Unban',
                '@css/slay'        => 'Slay',
                '@css/changemap'   => 'Change Map',
                '@css/vote'        => 'Vote',
                '@css/cvar'        => 'Cvar',
                '@css/config'      => 'Exec Config',
                '@css/password'    => 'Server Password',
                '@css/showip'      => 'Show IP',
                '@css/rcon'        => 'RCON',
                '@css/cheats'      => 'Cheats',
                '@css/root'        => 'Root',
                '@css/skipafk'     => 'Skip AFK',
                '@css/skipspec'    => 'Skip Spectators',
                '@css/permmute'    => 'Permanent Mute',
                '@css/vip'         => 'VIP',
                '@admin/warn'      => 'Warn',
                '@admin/unwarn'    => 'Unwarn',
            ],
            'K4-Zenith' => [
                '@zenith/root'         => 'Zenith Root',
                '@zenith/admin'        => 'Zenith Admin',
                '@zenith-bans/admin'   => 'Zenith Bans Admin',
                '@zenith/commands'     => 'Command List',
                '@zenith/reload'       => 'Reload Configs',
                '@zenith/placeholders' => 'Placeholder List',
            ],
            'K4-System' => [
                '@k4system/admin'                 => 'K4 System Admin',
                '@k4system/vip/points-multiplier' => 'VIP Points Multiplier',
                '@k4system/rank/vip'              => 'VIP Rank Permission',
            ],
        ];

        // The K4-Zenith flag group is only relevant when that integration is on.
        if (!Settings::bool('zenith_enabled', true)) {
            unset($groups['K4-Zenith']);
        }
        return $groups;
    }

    /**
     * The checkbox caption for a flag: "Reserved Slot (@css/reservation)".
     */
    public static function label(string $flag, ?string $name = null): string
    {
        $name ??= self::all()[$flag] ?? '';
        return $name !== '' ? $name . ' (' . $flag . ')' : $flag;
    }

    /**
     * Flat map of flag => human label (all groups merged).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::grouped() as $flags) {
            foreach ($flags as $flag => $label) {
                $out[$flag] = $label;
            }
        }
        return $out;
    }

    /**
     * Custom flags the admins added themselves. Persisted in panel_settings so
     * they keep showing up as checkboxes on every later edit.
     *
     * @return string[]
     */
    public static function custom(): array
    {
        static $cleaned = false;
        $raw = (string) Settings::get('custom_flags', '');
        $list = preg_split('/[\r\n,]+/', $raw) ?: [];
        $known = self::all();
        $list = array_filter(array_map('trim', $list), static fn($f) => self::isValid($f) && !isset($known[$f]));
        $list = array_values(array_unique($list));
        if (!$cleaned) {
            $cleaned = true;
            $normalized = implode("\n", $list);
            if ($normalized !== $raw) {
                try {
                    Settings::set('custom_flags', $normalized);
                } catch (\Throwable $e) {
                    error_log('[CS2Panel] custom flags cleanup failed: ' . $e->getMessage());
                }
            }
        }
        return $list;
    }

    /**
     * Remember any flag that isn't part of the built-in list, so it becomes a
     * regular checkbox afterwards.
     *
     * @param string[] $flags
     */
    public static function remember(array $flags): void
    {
        $known  = self::all();
        $custom = self::custom();
        $added  = false;
        foreach ($flags as $flag) {
            $flag = is_string($flag) ? trim($flag) : '';
            if ($flag === '' || isset($known[$flag]) || in_array($flag, $custom, true) || !self::isValid($flag)) {
                continue;
            }
            $custom[] = $flag;
            $added = true;
        }
        if ($added) {
            Settings::set('custom_flags', implode("\n", $custom));
        }
    }

    /** A valid flag starts with '@', has no whitespace and is reasonably short. */
    public static function isValid(string $flag): bool
    {
        return $flag !== ''
            && $flag[0] === '@'
            && strlen($flag) <= 191
            && !preg_match('/\s/', $flag)
                && !self::isPanelFlag($flag)
                && !self::isRetiredFlag($flag);
    }

            /** Flags intentionally removed from the available server-permission UI. */
            public static function isRetiredFlag(string $flag): bool
            {
            return in_array(strtolower(trim($flag)), ['@vip/vip', '@admin/generic', '@admin/root'], true);
            }

    /** Panel permissions are not CounterStrikeSharp server-admin flags. */
    public static function isPanelFlag(string $flag): bool
    {
        return preg_match('/^@web(?:\/|$)/i', trim($flag)) === 1;
    }
}

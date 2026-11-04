<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Lang;
use App\Core\Paginator;
use App\Core\Settings;

/**
 * K4-System 7.x extras: daily/weekly missions, achievements and monthly seasons.
 *
 * Progress lives in the plugin's tables (<prefix>k4missions, k4missionstate,
 * k4achievements, k4seasons, k4seasonstate). Definitions live in the plugin's
 * missions.jsonc / achievements.jsonc, which the owner uploads in Settings; the
 * plugin's shipped files are bundled as the fallback. Texts come from the
 * plugin's own language files, then from a title derived from the key.
 */
final class K4ExtrasModel
{
    public const DB = 'k4system';
    public const WEEKLY_SLOT = 3;
    public const DIFFICULTIES = ['Easy', 'Medium', 'Hard', 'Weekly'];
    public const DEFAULT_REWARDS = ['easy' => 150, 'medium' => 300, 'hard' => 600, 'weekly' => 2500];
    public const DEFAULT_TIMEZONE = 'Europe/Istanbul';

    private const DATA_DIR = '/app/Data/k4system';
    private const ICON_BASE = '/assets/img/k4/';

    /** Mission type -> plugin language key (MissionDefinition.DescriptionKey). */
    private const MISSION_KEYS = [
        'Kills' => 'mission.kills', 'Headshots' => 'mission.headshots', 'KnifeKills' => 'mission.knife-kills',
        'GrenadeKills' => 'mission.grenade-kills', 'NoscopeKills' => 'mission.noscope-kills',
        'WeaponKills' => 'mission.weapon-kills', 'Assists' => 'mission.assists', 'FlashAssists' => 'mission.flash-assists',
        'Mvps' => 'mission.mvps', 'RoundWins' => 'mission.round-wins', 'BombPlants' => 'mission.bomb-plants',
        'BombDefuses' => 'mission.bomb-defuses', 'HostageRescues' => 'mission.hostage-rescues',
        'PlaytimeMinutes' => 'mission.playtime', 'MatchesPlayed' => 'mission.matches-played',
        'MatchWins' => 'mission.match-wins', 'WallbangKills' => 'mission.wallbang-kills',
        'SmokeKills' => 'mission.smoke-kills', 'JumpKills' => 'mission.jump-kills', 'BlindKills' => 'mission.blind-kills',
        'LongRangeKills' => 'mission.long-range-kills', 'ZeusKills' => 'mission.zeus-kills',
        'OpeningKills' => 'mission.opening-kills', 'ClutchWins' => 'mission.clutch-wins',
        'RoundKills' => 'mission.round-kills', 'SurviveRounds' => 'mission.survive-rounds',
        'PistolKills' => 'mission.pistol-kills', 'SmgKills' => 'mission.smg-kills',
        'ShotgunKills' => 'mission.shotgun-kills', 'RifleKills' => 'mission.rifle-kills',
        'SniperKills' => 'mission.sniper-kills', 'Damage' => 'mission.damage', 'LifeKills' => 'mission.life-kills',
        'RevengeKills' => 'mission.revenge-kills',
    ];

    /** Mission type -> panorama icon (PanoramaHud.MissionIcon). */
    private const MISSION_ICONS = [
        'Kills' => 'ui/kill', 'Headshots' => 'ui/kill_headshot', 'KnifeKills' => 'equipment/knife',
        'GrenadeKills' => 'equipment/hegrenade', 'NoscopeKills' => 'equipment/awp', 'Assists' => 'ui/stat_assists',
        'FlashAssists' => 'equipment/flashbang_assist', 'Mvps' => 'ui/stat_mvps', 'RoundWins' => 'ui/trophy',
        'BombPlants' => 'ui/bomb_c4', 'BombDefuses' => 'equipment/defuser', 'HostageRescues' => 'ui/hostage_rescued',
        'PlaytimeMinutes' => 'ui/clock', 'MatchesPlayed' => 'ui/play', 'MatchWins' => 'ui/winner_ribbon',
        'WallbangKills' => 'deathnotice/penetrate', 'SmokeKills' => 'deathnotice/smoke_kill',
        'JumpKills' => 'deathnotice/inairkill', 'BlindKills' => 'deathnotice/blind_kill',
        'LongRangeKills' => 'ui/crosshair', 'ZeusKills' => 'equipment/taser', 'OpeningKills' => 'ui/first_person',
        'ClutchWins' => 'ui/multipleenemieshere', 'RoundKills' => 'ui/icon_star_multi', 'SurviveRounds' => 'ui/shield',
        'PistolKills' => 'equipment/deagle', 'SmgKills' => 'equipment/mp9', 'ShotgunKills' => 'equipment/nova',
        'RifleKills' => 'equipment/ak47', 'SniperKills' => 'equipment/awp', 'Damage' => 'ui/health',
        'LifeKills' => 'deathnotice/domination', 'RevengeKills' => 'deathnotice/revenge',
        'PlaytimeHours' => 'ui/clock', 'MissionStreak' => 'ui/hot_trend',
    ];

    /** Achievement type -> k4stats column (AchievementRules.StatOf). */
    private const ACHIEVEMENT_STATS = [
        'Kills' => 'kills', 'Headshots' => 'headshots', 'Assists' => 'assists', 'Mvps' => 'mvp',
        'RoundWins' => 'round_win', 'MatchWins' => 'game_win', 'OpeningKills' => 'firstblood',
        'BombPlants' => 'bomb_planted', 'BombDefuses' => 'bomb_defused', 'HostageRescues' => 'hostage_rescued',
        'NoscopeKills' => 'noscope_kill', 'WallbangKills' => 'penetrated_kill', 'SmokeKills' => 'thrusmoke_kill',
        'BlindKills' => 'flashed_kill', 'RevengeKills' => 'revenge_kill',
    ];

    /** WeaponCatalog.DisplayNames. */
    private const WEAPON_NAMES = [
        'deagle' => 'Desert Eagle', 'elite' => 'Dual Berettas', 'fiveseven' => 'Five-SeveN', 'glock' => 'Glock-18',
        'ak47' => 'AK-47', 'aug' => 'AUG', 'awp' => 'AWP', 'famas' => 'FAMAS', 'g3sg1' => 'G3SG1', 'galilar' => 'Galil AR',
        'm249' => 'M249', 'm4a1' => 'M4A4', 'm4a1_silencer' => 'M4A1-S', 'mac10' => 'MAC-10', 'p90' => 'P90',
        'mp5sd' => 'MP5-SD', 'ump45' => 'UMP-45', 'xm1014' => 'XM1014', 'bizon' => 'PP-Bizon', 'mag7' => 'MAG-7',
        'negev' => 'Negev', 'sawedoff' => 'Sawed-Off', 'tec9' => 'Tec-9', 'taser' => 'Zeus x27', 'hkp2000' => 'P2000',
        'mp7' => 'MP7', 'mp9' => 'MP9', 'nova' => 'Nova', 'p250' => 'P250', 'scar20' => 'SCAR-20', 'sg556' => 'SG 553',
        'ssg08' => 'SSG 08', 'usp_silencer' => 'USP-S', 'cz75a' => 'CZ75-Auto', 'revolver' => 'R8 Revolver',
        'knife' => 'Knife', 'hegrenade' => 'HE Grenade', 'molotov' => 'Molotov', 'incgrenade' => 'Incendiary',
    ];

    // ------------------------------------------------------------------
    // Availability
    // ------------------------------------------------------------------

    private static function prefix(): string
    {
        return (string) Settings::get('k4_prefix', (string) Config::get('k4system.prefix', ''));
    }

    public static function table(string $name): string
    {
        return self::prefix() . $name;
    }

    private static function ranksTable(): string
    {
        return self::prefix() . (string) Settings::get('k4_ranks_table', (string) Config::get('k4system.ranks_table', 'k4ranks'));
    }

    private static function statsTable(): string
    {
        return self::prefix() . (string) Settings::get('k4_stats_table', (string) Config::get('k4system.stats_table', 'k4stats'));
    }

    private static function timesTable(): string
    {
        return self::prefix() . (string) Settings::get('k4_times_table', (string) Config::get('k4system.times_table', 'k4times'));
    }

    private static function steamColumn(): string
    {
        return (string) Settings::get('k4_steam', (string) Config::get('k4system.steam_column', 'steam_id'));
    }

    private static function has(string $name): bool
    {
        try {
            return Database::tableExists(self::table($name), self::DB);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Page switched on in Settings, K4-System is the active stats backend and the tables exist. */
    public static function enabled(string $page): bool
    {
        static $cache = [];
        if (isset($cache[$page])) {
            return $cache[$page];
        }
        if (!in_array($page, ['missions', 'achievements', 'seasons'], true)
            || !Settings::bool('k4_' . $page . '_enabled', false)
            || !K4SystemModel::enabled()) {
            return $cache[$page] = false;
        }
        $table = ['missions' => 'k4missions', 'achievements' => 'k4achievements', 'seasons' => 'k4seasons'][$page];
        return $cache[$page] = self::has($table);
    }

    /** @return string[] enabled extra pages, in menu order. */
    public static function pages(): array
    {
        return array_values(array_filter(['missions', 'achievements', 'seasons'], [self::class, 'enabled']));
    }

    // ------------------------------------------------------------------
    // Definitions (missions.jsonc / achievements.jsonc)
    // ------------------------------------------------------------------

    /** Decode the plugin's JSONC: // and /* *\/ comments and trailing commas are allowed. */
    public static function parseJsonc(string $text): ?array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $out = '';
        $len = strlen($text);
        $inString = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($inString) {
                $out .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $out .= $text[++$i];
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
                $out .= $c;
            } elseif ($c === '/' && ($text[$i + 1] ?? '') === '/') {
                while ($i < $len && $text[$i] !== "\n") {
                    $i++;
                }
                $out .= "\n";
            } elseif ($c === '/' && ($text[$i + 1] ?? '') === '*') {
                $end = strpos($text, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
            } else {
                $out .= $c;
            }
        }
        $out = preg_replace('/,(\s*[}\]])/', '$1', $out) ?? $out;
        $data = json_decode($out, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Normalise an uploaded missions.jsonc. Returns the clean map or null when
     * the file is not a valid mission list.
     *
     * @return array<string,array{type:string,difficulty:string,target:int,weapon:?string,distance:?int,reward:?int}>|null
     */
    public static function normaliseMissions(array $raw): ?array
    {
        $out = [];
        foreach ($raw as $id => $m) {
            if (!is_string($id) || $id === '' || !is_array($m)) {
                continue;
            }
            $m = array_change_key_case($m, CASE_LOWER);
            $type = (string) ($m['type'] ?? '');
            $difficulty = ucfirst(strtolower((string) ($m['difficulty'] ?? '')));
            $target = (int) ($m['target'] ?? 0);
            if ($type === '' || !in_array($difficulty, self::DIFFICULTIES, true) || $target <= 0) {
                continue;
            }
            $out[$id] = [
                'type' => $type,
                'difficulty' => $difficulty,
                'target' => $target,
                'weapon' => isset($m['weapon']) && $m['weapon'] !== '' ? (string) $m['weapon'] : null,
                'distance' => isset($m['distance']) ? (int) $m['distance'] : null,
                'reward' => isset($m['reward']) ? (int) $m['reward'] : null,
            ];
        }
        return $out ?: null;
    }

    /** @return array<string,array{type:string,goal:int,points:int,name:?string}>|null */
    public static function normaliseAchievements(array $raw): ?array
    {
        $out = [];
        foreach ($raw as $id => $a) {
            if (!is_string($id) || $id === '' || !is_array($a)) {
                continue;
            }
            $a = array_change_key_case($a, CASE_LOWER);
            $type = (string) ($a['type'] ?? '');
            $goal = (int) ($a['goal'] ?? 0);
            if ($type === '' || $goal <= 0) {
                continue;
            }
            $out[$id] = [
                'type' => $type,
                'goal' => $goal,
                'points' => (int) ($a['points'] ?? 0),
                'name' => isset($a['name']) && trim((string) $a['name']) !== '' ? trim((string) $a['name']) : null,
            ];
        }
        return $out ?: null;
    }

    /** True when the owner uploaded their own file ('missions' | 'achievements'). */
    public static function isCustom(string $kind): bool
    {
        return trim((string) Settings::get('k4_' . $kind . '_def', '')) !== '';
    }

    private static function definitions(string $kind): array
    {
        static $cache = [];
        if (isset($cache[$kind])) {
            return $cache[$kind];
        }
        $stored = json_decode((string) Settings::get('k4_' . $kind . '_def', ''), true);
        if (is_array($stored) && $stored) {
            return $cache[$kind] = $stored;
        }
        $file = BASE_PATH . self::DATA_DIR . '/' . $kind . '.jsonc';
        $raw = is_file($file) ? self::parseJsonc((string) file_get_contents($file)) : null;
        $norm = $raw === null ? null
            : ($kind === 'missions' ? self::normaliseMissions($raw) : self::normaliseAchievements($raw));
        return $cache[$kind] = $norm ?? [];
    }

    public static function missions(): array
    {
        return self::definitions('missions');
    }

    public static function achievements(): array
    {
        return self::definitions('achievements');
    }

    // ------------------------------------------------------------------
    // Plugin translations + derived titles
    // ------------------------------------------------------------------

    private static function pluginText(string $key): ?string
    {
        static $strings = [];
        foreach (array_unique([Lang::current(), 'en']) as $code) {
            if (!isset($strings[$code])) {
                $file = BASE_PATH . self::DATA_DIR . '/lang/' . preg_replace('/[^a-z]/', '', $code) . '.json';
                $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                $strings[$code] = is_array($data) ? $data : [];
            }
            if (isset($strings[$code][$key]) && $strings[$code][$key] !== '') {
                return (string) $strings[$code][$key];
            }
        }
        return null;
    }

    /** "awp-kills-10" / "WeaponKills" -> "Awp Kills 10" / "Weapon Kills". */
    public static function humanize(string $key): string
    {
        $s = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key) ?? $key;
        $s = trim(preg_replace('/[\s_\-.]+/', ' ', $s) ?? $s);
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }

    private static function format(string $text, array $args): string
    {
        foreach ($args as $i => $v) {
            $text = str_replace('{' . $i . '}', (string) $v, $text);
        }
        return $text;
    }

    public static function weaponName(?string $weapon): string
    {
        $w = self::normaliseWeapon($weapon);
        return self::WEAPON_NAMES[$w] ?? strtoupper($w);
    }

    private static function normaliseWeapon(?string $weapon): string
    {
        $w = strtolower(trim((string) $weapon));
        return str_starts_with($w, 'weapon_') ? substr($w, 7) : $w;
    }

    public static function difficultyLabel(string $difficulty): string
    {
        return self::pluginText('mission.' . strtolower($difficulty)) ?? $difficulty;
    }

    /** "Get 15 kills", "Get 10 kills with the AWP", ... */
    public static function describeMission(string $id, ?array $def): string
    {
        if ($def === null) {
            return self::humanize($id);
        }
        $key = self::MISSION_KEYS[$def['type']] ?? null;
        $arg1 = $def['type'] === 'WeaponKills' ? self::weaponName($def['weapon'])
            : ($def['type'] === 'LongRangeKills' ? (string) ($def['distance'] ?? 30) : '');
        $text = $key !== null ? self::pluginText($key) : null;
        if ($text === null) {
            return self::humanize($id);
        }
        return self::format($text, [number_format($def['target']), $arg1]);
    }

    public static function achievementName(string $id, ?array $def): string
    {
        if ($def !== null && !empty($def['name'])) {
            return (string) $def['name'];
        }
        return self::pluginText('achievement.name.' . $id) ?? self::humanize($id);
    }

    public static function achievementGoal(?array $def): string
    {
        if ($def === null) {
            return '';
        }
        $key = match ($def['type']) {
            'PlaytimeHours' => 'achievement.goal.playtime-hours',
            'MissionStreak' => 'achievement.goal.mission-streak',
            default => self::MISSION_KEYS[$def['type']] ?? null,
        };
        $text = $key !== null ? self::pluginText($key) : null;
        return $text !== null
            ? self::format($text, [number_format($def['goal']), ''])
            : self::humanize($def['type']) . ' · ' . number_format($def['goal']);
    }

    /** Panorama SVG (used as a CSS mask so it takes the theme colour). Inline style URLs are not base-rewritten, so they carry the subfolder prefix. */
    public static function icon(string $type, ?string $weapon = null): string
    {
        $rel = 'ui/mission_star';
        if ($type === 'WeaponKills') {
            $w = self::normaliseWeapon($weapon);
            $rel = is_file((defined('PUBLIC_PATH') ? PUBLIC_PATH : BASE_PATH) . self::ICON_BASE . 'equipment/' . $w . '.svg') ? 'equipment/' . $w : 'ui/bullet';
        } elseif (isset(self::MISSION_ICONS[$type])) {
            $rel = self::MISSION_ICONS[$type];
        }
        return \App\Core\Url::to(self::ICON_BASE . $rel . '.svg');
    }

    public static function uiIcon(string $name): string
    {
        return \App\Core\Url::to(self::ICON_BASE . 'ui/' . preg_replace('/[^a-z0-9_]/', '', $name) . '.svg');
    }

    /** bronze < 300 points, silver < 800, gold from 800 (AchievementRules.Tier). */
    public static function tier(int $points): string
    {
        return $points < 300 ? 'bronze' : ($points < 800 ? 'silver' : 'gold');
    }

    public static function reward(array $def): int
    {
        if ($def['reward'] !== null) {
            return (int) $def['reward'];
        }
        $d = strtolower($def['difficulty']);
        return (int) Settings::get('k4_reward_' . $d, (string) (self::DEFAULT_REWARDS[$d] ?? 0));
    }

    // ------------------------------------------------------------------
    // Mission calendar (missions.timezone)
    // ------------------------------------------------------------------

    public static function timezone(): \DateTimeZone
    {
        $tz = trim((string) Settings::get('k4_missions_tz', self::DEFAULT_TIMEZONE));
        try {
            return new \DateTimeZone($tz !== '' ? $tz : self::DEFAULT_TIMEZONE);
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone());
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function weekStart(): string
    {
        $now = self::now();
        $back = ((int) $now->format('N')) - 1;
        return $now->modify('-' . $back . ' days')->format('Y-m-d');
    }

    /** Seconds until the daily missions reset, the weekly one and the season. */
    public static function countdowns(): array
    {
        $now = self::now();
        $midnight = $now->setTime(0, 0)->modify('+1 day');
        $back = ((int) $now->format('N')) - 1;
        $week = $now->setTime(0, 0)->modify('-' . $back . ' days')->modify('+7 days');
        $month = $now->setTime(0, 0)->modify('first day of next month');
        return [
            'day' => max(0, $midnight->getTimestamp() - $now->getTimestamp()),
            'week' => max(0, $week->getTimestamp() - $now->getTimestamp()),
            'season' => max(0, $month->getTimestamp() - $now->getTimestamp()),
        ];
    }

    // ------------------------------------------------------------------
    // Players
    // ------------------------------------------------------------------

    /** Name + points of a player from k4ranks. */
    public static function player(string $steamId): ?array
    {
        $sc = self::steamColumn();
        try {
            return Database::one(
                "SELECT `{$sc}` AS steam_id, `name`, `points`, `rank` AS rank_name, `lastseen` FROM `" . self::ranksTable() . "` WHERE `{$sc}` = ? LIMIT 1",
                [$steamId],
                self::DB
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Find players by SteamID64 or name. @return array<int,array{steam_id:string,name:string}> */
    public static function findPlayers(string $q, int $limit = 8): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $sc = self::steamColumn();
        $id = \App\Core\Steam::toSteamId64Any($q);
        try {
            if ($id !== null) {
                return Database::all("SELECT `{$sc}` AS steam_id, `name` FROM `" . self::ranksTable() . "` WHERE `{$sc}` = ? LIMIT 1", [$id], self::DB);
            }
            $like = '%' . addcslashes($q, '%_\\') . '%';
            return Database::all(
                "SELECT `{$sc}` AS steam_id, `name` FROM `" . self::ranksTable() . "` WHERE `name` LIKE ? ORDER BY `lastseen` DESC LIMIT " . max(1, $limit),
                [$like],
                self::DB
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Missions
    // ------------------------------------------------------------------

    /**
     * Today's three daily missions plus this week's one for a player.
     * @return array{daily:array<int,array>,weekly:?array,streak:int,best:int,rerolls:int,done:int}
     */
    public static function playerMissions(string $steamId): array
    {
        $defs = self::missions();
        $today = self::today();
        $week = self::weekStart();
        $rows = [];
        try {
            $rows = Database::all(
                'SELECT `slot`, `mission_id`, `progress`, `completed`, `day` FROM `' . self::table('k4missions') . '`
                 WHERE `steam_id` = ? AND ((`day` = ? AND `slot` < ' . self::WEEKLY_SLOT . ') OR (`day` = ? AND `slot` = ' . self::WEEKLY_SLOT . '))
                 ORDER BY `slot`',
                [$steamId, $today, $week],
                self::DB
            );
        } catch (\Throwable $e) {
        }

        $daily = [];
        $weekly = null;
        $done = 0;
        foreach ($rows as $r) {
            $card = self::missionCard((string) $r['mission_id'], (int) $r['progress'], (bool) $r['completed'], $defs);
            if ((int) $r['slot'] === self::WEEKLY_SLOT) {
                $weekly = $card;
            } else {
                $daily[(int) $r['slot']] = $card;
                $done += $card['completed'] ? 1 : 0;
            }
        }
        ksort($daily);

        $state = ['streak' => 0, 'rerolls' => 0];
        if (self::has('k4missionstate')) {
            try {
                $s = Database::one('SELECT `streak`, `last_completed_day`, `reroll_day`, `rerolls_used` FROM `' . self::table('k4missionstate') . '` WHERE `steam_id` = ?', [$steamId], self::DB);
                if ($s) {
                    $yesterday = self::now()->modify('-1 day')->format('Y-m-d');
                    $last = (string) ($s['last_completed_day'] ?? '');
                    $state['streak'] = ($last === $today || $last === $yesterday) ? (int) $s['streak'] : 0;
                    $state['rerolls'] = (string) ($s['reroll_day'] ?? '') === $today ? (int) $s['rerolls_used'] : 0;
                }
            } catch (\Throwable $e) {
            }
        }

        return ['daily' => array_values($daily), 'weekly' => $weekly, 'done' => $done] + $state;
    }

    private static function missionCard(string $id, int $progress, bool $completed, array $defs): array
    {
        $def = $defs[$id] ?? null;
        $target = $def['target'] ?? max(1, $progress);
        $progress = min($progress, $target);
        return [
            'id' => $id,
            'title' => self::describeMission($id, $def),
            'difficulty' => $def['difficulty'] ?? 'Easy',
            'difficulty_label' => self::difficultyLabel($def['difficulty'] ?? 'Easy'),
            'icon' => self::icon($def['type'] ?? '', $def['weapon'] ?? null),
            'progress' => $progress,
            'target' => $target,
            'percent' => $completed ? 100 : (int) floor($progress * 100 / max(1, $target)),
            'completed' => $completed,
            'reward' => $def ? self::reward($def) : 0,
            'known' => $def !== null,
        ];
    }

    /**
     * Every mission handed out on the server today (daily) and this week (weekly), with how many
     * players got it and finished it. Shown when no player is selected.
     * @return array<int,array>
     */
    public static function activeMissions(): array
    {
        $defs = self::missions();
        try {
            $rows = Database::all(
                'SELECT `mission_id`, MAX(`slot` = ' . self::WEEKLY_SLOT . ') AS weekly, COUNT(*) AS assigned, SUM(`completed`) AS done
                 FROM `' . self::table('k4missions') . '`
                 WHERE (`day` = ? AND `slot` < ' . self::WEEKLY_SLOT . ') OR (`day` = ? AND `slot` = ' . self::WEEKLY_SLOT . ')
                 GROUP BY `mission_id`',
                [self::today(), self::weekStart()],
                self::DB
            );
        } catch (\Throwable $e) {
            return [];
        }
        $order = ['Easy' => 0, 'Medium' => 1, 'Hard' => 2, 'Weekly' => 3];
        $out = [];
        foreach ($rows as $r) {
            $assigned = (int) $r['assigned'];
            $done = (int) $r['done'];
            $card = self::missionCard((string) $r['mission_id'], 0, false, $defs);
            if ((int) $r['weekly'] === 1) {
                $card['difficulty'] = 'Weekly';
                $card['difficulty_label'] = self::difficultyLabel('Weekly');
            }
            $card['assigned'] = $assigned;
            $card['done'] = $done;
            $card['percent'] = $assigned > 0 ? (int) round($done * 100 / $assigned) : 0;
            $out[] = $card;
        }
        usort($out, fn($a, $b) => ($order[$a['difficulty']] ?? 9) <=> ($order[$b['difficulty']] ?? 9)
            ?: $b['assigned'] <=> $a['assigned'] ?: $a['target'] <=> $b['target']);
        return $out;
    }

    /** Server-wide numbers for today. */
    public static function missionsOverview(): array
    {
        $today = self::today();
        $out = ['players' => 0, 'completed' => 0, 'perfect' => 0, 'weekly' => 0];
        try {
            $row = Database::one(
                'SELECT COUNT(DISTINCT `steam_id`) AS players, COALESCE(SUM(`completed`), 0) AS completed FROM `' . self::table('k4missions') . '` WHERE `day` = ? AND `slot` < ' . self::WEEKLY_SLOT,
                [$today],
                self::DB
            );
            $out['players'] = (int) ($row['players'] ?? 0);
            $out['completed'] = (int) ($row['completed'] ?? 0);
            $out['perfect'] = (int) Database::scalar(
                'SELECT COUNT(*) FROM (SELECT `steam_id` FROM `' . self::table('k4missions') . '` WHERE `day` = ? AND `slot` < ' . self::WEEKLY_SLOT . ' GROUP BY `steam_id` HAVING SUM(`completed`) >= 3) t',
                [$today],
                self::DB
            );
            $out['weekly'] = (int) Database::scalar(
                'SELECT COUNT(*) FROM `' . self::table('k4missions') . '` WHERE `day` = ? AND `slot` = ' . self::WEEKLY_SLOT . ' AND `completed` = 1',
                [self::weekStart()],
                self::DB
            );
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** The most popular missions today, with their completion rate. */
    public static function missionsToday(int $limit = 8): array
    {
        $defs = self::missions();
        try {
            $rows = Database::all(
                'SELECT `mission_id`, COUNT(*) AS assigned, SUM(`completed`) AS done FROM `' . self::table('k4missions') . '`
                 WHERE `day` = ? AND `slot` < ' . self::WEEKLY_SLOT . ' GROUP BY `mission_id` ORDER BY assigned DESC, done DESC LIMIT ' . max(1, $limit),
                [self::today()],
                self::DB
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (string) $r['mission_id'];
            $def = $defs[$id] ?? null;
            $assigned = (int) $r['assigned'];
            $out[] = [
                'title' => self::describeMission($id, $def),
                'difficulty' => $def['difficulty'] ?? 'Easy',
                'difficulty_label' => self::difficultyLabel($def['difficulty'] ?? 'Easy'),
                'icon' => self::icon($def['type'] ?? '', $def['weapon'] ?? null),
                'assigned' => $assigned,
                'done' => (int) $r['done'],
                'rate' => $assigned > 0 ? (int) round(((int) $r['done']) * 100 / $assigned) : 0,
            ];
        }
        return $out;
    }

    /** Longest live streaks. */
    public static function streakLeaders(int $limit = 10): array
    {
        if (!self::has('k4missionstate')) {
            return [];
        }
        $sc = self::steamColumn();
        $yesterday = self::now()->modify('-1 day')->format('Y-m-d');
        try {
            return Database::all(
                'SELECT s.`steam_id`, COALESCE(r.`name`, \'\') AS name, s.`streak` FROM `' . self::table('k4missionstate') . '` s
                 LEFT JOIN `' . self::ranksTable() . "` r ON r.`{$sc}` = s.`steam_id`
                 WHERE s.`streak` > 0 AND s.`last_completed_day` IN (?, ?) ORDER BY s.`streak` DESC LIMIT " . max(1, $limit),
                [self::today(), $yesterday],
                self::DB
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Achievements
    // ------------------------------------------------------------------

    /** Every achievement with the player's progress, unlock date and server rarity. */
    public static function playerAchievements(?string $steamId): array
    {
        $defs = self::achievements();
        $unlocked = [];
        $counters = ['stats' => null, 'playtime' => null, 'streak' => null];

        if ($steamId !== null) {
            try {
                foreach (Database::all('SELECT `achievement_id`, `unlocked_at` FROM `' . self::table('k4achievements') . '` WHERE `steam_id` = ?', [$steamId], self::DB) as $r) {
                    $unlocked[(string) $r['achievement_id']] = (string) $r['unlocked_at'];
                }
            } catch (\Throwable $e) {
            }
            $sc = self::steamColumn();
            try {
                if (Database::tableExists(self::statsTable(), self::DB)) {
                    $counters['stats'] = Database::one("SELECT * FROM `" . self::statsTable() . "` WHERE `{$sc}` = ?", [$steamId], self::DB) ?: [];
                }
                if (Database::tableExists(self::timesTable(), self::DB)) {
                    $counters['playtime'] = (int) Database::scalar("SELECT `all` FROM `" . self::timesTable() . "` WHERE `{$sc}` = ?", [$steamId], self::DB);
                }
                if (self::has('k4missionstate')) {
                    $counters['streak'] = (int) Database::scalar('SELECT `streak` FROM `' . self::table('k4missionstate') . '` WHERE `steam_id` = ?', [$steamId], self::DB);
                }
            } catch (\Throwable $e) {
            }
        }

        $rarity = self::rarity();
        $out = [];
        foreach ($defs as $id => $def) {
            $progress = match ($def['type']) {
                'PlaytimeHours' => $counters['playtime'] !== null ? intdiv($counters['playtime'], 3600) : null,
                'MissionStreak' => $counters['streak'],
                default => isset(self::ACHIEVEMENT_STATS[$def['type']]) && is_array($counters['stats'])
                    ? (int) ($counters['stats'][self::ACHIEVEMENT_STATS[$def['type']]] ?? 0) : null,
            };
            $isUnlocked = isset($unlocked[$id]);
            $out[] = [
                'id' => $id,
                'name' => self::achievementName($id, $def),
                'goal_text' => self::achievementGoal($def),
                'icon' => self::icon($def['type']),
                'tier' => self::tier($def['points']),
                'points' => $def['points'],
                'goal' => $def['goal'],
                'progress' => $isUnlocked ? $def['goal'] : min((int) ($progress ?? 0), $def['goal']),
                'percent' => $isUnlocked ? 100 : (int) floor(min((int) ($progress ?? 0), $def['goal']) * 100 / max(1, $def['goal'])),
                'tracked' => $progress !== null || $isUnlocked,
                'unlocked' => $isUnlocked,
                'unlocked_at' => $unlocked[$id] ?? null,
                'rarity' => $rarity['percent'][$id] ?? 0.0,
                'holders' => $rarity['count'][$id] ?? 0,
            ];
        }
        return $out;
    }

    /** Share of ranked players holding each achievement. */
    public static function rarity(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = ['count' => [], 'percent' => [], 'players' => 0];
        try {
            $players = (int) Database::scalar('SELECT COUNT(*) FROM `' . self::ranksTable() . '`', [], self::DB);
            $cache['players'] = $players;
            foreach (Database::all('SELECT `achievement_id`, COUNT(*) AS c FROM `' . self::table('k4achievements') . '` GROUP BY `achievement_id`', [], self::DB) as $r) {
                $id = (string) $r['achievement_id'];
                $cache['count'][$id] = (int) $r['c'];
                $cache['percent'][$id] = $players > 0 ? round(((int) $r['c']) * 100 / $players, 1) : 0.0;
            }
        } catch (\Throwable $e) {
        }
        return $cache;
    }

    /** Latest unlocks across the server. */
    public static function recentUnlocks(int $limit = 8): array
    {
        $defs = self::achievements();
        $sc = self::steamColumn();
        try {
            $rows = Database::all(
                'SELECT a.`steam_id`, a.`achievement_id`, a.`unlocked_at`, COALESCE(r.`name`, \'\') AS name FROM `' . self::table('k4achievements') . '` a
                 LEFT JOIN `' . self::ranksTable() . "` r ON r.`{$sc}` = a.`steam_id`
                 ORDER BY a.`unlocked_at` DESC LIMIT " . max(1, $limit),
                [],
                self::DB
            );
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as &$r) {
            $def = $defs[(string) $r['achievement_id']] ?? null;
            $r['title'] = self::achievementName((string) $r['achievement_id'], $def);
            $r['icon'] = self::icon($def['type'] ?? '');
            $r['tier'] = self::tier((int) ($def['points'] ?? 0));
        }
        unset($r);
        return $rows;
    }

    /** Players with the most achievements. */
    public static function achievementLeaders(int $limit = 10): array
    {
        $sc = self::steamColumn();
        try {
            return Database::all(
                'SELECT a.`steam_id`, COALESCE(r.`name`, \'\') AS name, COUNT(*) AS total FROM `' . self::table('k4achievements') . '` a
                 LEFT JOIN `' . self::ranksTable() . "` r ON r.`{$sc}` = a.`steam_id`
                 GROUP BY a.`steam_id`, r.`name` ORDER BY total DESC, MIN(a.`unlocked_at`) ASC LIMIT " . max(1, $limit),
                [],
                self::DB
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Seasons
    // ------------------------------------------------------------------

    public static function currentSeason(): ?string
    {
        if (self::has('k4seasonstate')) {
            try {
                $s = Database::scalar('SELECT `season` FROM `' . self::table('k4seasonstate') . '` ORDER BY `id` DESC LIMIT 1', [], self::DB);
                if (is_string($s) && $s !== '') {
                    return $s;
                }
            } catch (\Throwable $e) {
            }
        }
        return self::now()->format('Y-m-01');
    }

    /** @return string[] every season, newest first. */
    public static function seasons(): array
    {
        try {
            $list = array_map('strval', array_column(
                Database::all('SELECT DISTINCT `season` FROM `' . self::table('k4seasons') . '` ORDER BY `season` DESC', [], self::DB),
                'season'
            ));
        } catch (\Throwable $e) {
            $list = [];
        }
        $current = self::currentSeason();
        if ($current !== null && !in_array($current, $list, true)) {
            array_unshift($list, $current);
        }
        rsort($list);
        return $list;
    }

    /** Standings of one season. The running season reads live points from k4ranks. */
    public static function seasonStandings(string $season, int $page, int $perPage, string $search = ''): Paginator
    {
        $sc = self::steamColumn();
        $live = $season === self::currentSeason();
        if ($live) {
            $base = "SELECT r.`{$sc}` AS steam_id, r.`name` AS name, r.`points` AS points, r.`rank` AS rank_name,
                            GREATEST(r.`points`, COALESCE(s.`peak_points`, 0)) AS peak_points
                     FROM `" . self::ranksTable() . "` r
                     LEFT JOIN `" . self::table('k4seasons') . "` s ON s.`steam_id` = r.`{$sc}` AND s.`season` = ?";
            $count = 'SELECT COUNT(*) FROM `' . self::ranksTable() . "` r
                      LEFT JOIN `" . self::table('k4seasons') . "` s ON s.`steam_id` = r.`{$sc}` AND s.`season` = ?";
            return Paginator::filtered($base, $count, [$season], $page, $perPage, 'points DESC', self::DB, $search);
        }
        $base = 'SELECT s.`steam_id` AS steam_id, s.`name` AS name, COALESCE(s.`points`, s.`peak_points`) AS points, s.`rank` AS rank_name, s.`peak_points`
                 FROM `' . self::table('k4seasons') . '` s WHERE s.`season` = ?';
        $count = 'SELECT COUNT(*) FROM `' . self::table('k4seasons') . '` s WHERE s.`season` = ?';
        return Paginator::filtered($base, $count, [$season], $page, $perPage, 'points DESC', self::DB, $search);
    }

    /** One player's finished seasons with final position. */
    public static function playerSeasons(string $steamId): array
    {
        try {
            $rows = Database::all(
                'SELECT `season`, `points`, `peak_points`, `rank` AS rank_name FROM `' . self::table('k4seasons') . '` WHERE `steam_id` = ? ORDER BY `season` DESC LIMIT 12',
                [$steamId],
                self::DB
            );
            foreach ($rows as &$r) {
                $final = $r['points'] ?? $r['peak_points'];
                $r['position'] = $r['points'] === null ? null : 1 + (int) Database::scalar(
                    'SELECT COUNT(*) FROM `' . self::table('k4seasons') . '` WHERE `season` = ? AND COALESCE(`points`, `peak_points`) > ?',
                    [$r['season'], (int) $final],
                    self::DB
                );
            }
            unset($r);
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** "2026-11-01" -> "November 2026" in the panel language. */
    public static function seasonLabel(string $season): string
    {
        $ts = strtotime($season);
        if ($ts === false) {
            return $season;
        }
        if (class_exists(\IntlDateFormatter::class)) {
            $f = new \IntlDateFormatter(Lang::current(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'LLLL y');
            $label = $f->format($ts);
            if (is_string($label) && $label !== '') {
                return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
            }
        }
        return date('F Y', $ts);
    }
}

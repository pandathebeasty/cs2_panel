<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Core\WeaponPaintsData;

/**
 * WeaponPaints integration — reads/writes the plugin's wp_player_* tables and
 * ports the standalone module's includes/save.php equip logic natively.
 *
 * Reference: d:/CS2/skins/includes/save.php and d:/CS2/skins/index.php.
 * The source hardcodes the `wp_` prefix and uses named PDO placeholders; this
 * model resolves the prefix from Settings (default `wp_`) and rewrites every
 * query to the panel idiom (App\Core\Database with positional `?` params).
 *
 * All queries are defensive: when a table is missing they return []/null and
 * save() silently no-ops, so the page degrades gracefully without the plugin.
 */
final class WeaponPaintsModel
{
    /** Connection key: WeaponPaints's own database when configured, else the panel one. */
    private const DB = 'weaponpaints';

    private static function prefix(): string
    {
        return (string) Settings::get('wp_prefix', (string) Config::get('weaponpaints.prefix', 'wp_'));
    }

    private static function skinsTable(): string
    {
        return self::prefix() . 'player_skins';
    }

    private static function knifeTable(): string
    {
        return self::prefix() . 'player_knife';
    }

    private static function glovesTable(): string
    {
        return self::prefix() . 'player_gloves';
    }

    private static function agentsTable(): string
    {
        return self::prefix() . 'player_agents';
    }

    private static function musicTable(): string
    {
        return self::prefix() . 'player_music';
    }

    private static function pinsTable(): string
    {
        return self::prefix() . 'player_pins';
    }

    /** Enabled when the setting is on AND the core player_skins table exists. */
    public static function enabled(): bool
    {
        return Settings::bool('skins_enabled', false) && Database::tableExists(self::skinsTable(), self::DB);
    }

                                                                        
    // Reads (each guarded by tableExists -> []/null when the table is gone)
                                                                        

    /**
     * Does the player_skins table carry the sticker/keychain columns? WeaponPaints
     * (current main) stores up to 4 stickers + a keychain inline on each skin row
     * as `weapon_sticker_0..3` and `weapon_keychain`. Older installs don't have
     * them, so every sticker/keychain read & write is guarded by this (cached).
     */
    public static function hasStickerColumns(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = Database::columnExists(self::skinsTable(), 'weapon_sticker_0', self::DB)
                && Database::columnExists(self::skinsTable(), 'weapon_keychain', self::DB);
        }
        return $has;
    }

    /** The 5 CS2 sticker positions (0..4) stored on a skin row. */
    public const STICKER_SLOTS = 5;

    public static function playerSkins(string $steamid): array
    {
        if (!Database::tableExists(self::skinsTable(), self::DB)) {
            return [];
        }
        $stickerCols = self::hasStickerColumns()
            ? ', `weapon_sticker_0`, `weapon_sticker_1`, `weapon_sticker_2`, `weapon_sticker_3`, `weapon_sticker_4`, `weapon_keychain`'
            : '';
        return Database::all(
            'SELECT `weapon_defindex`, `weapon_paint_id`, `weapon_wear`, `weapon_seed`, `weapon_team`,
                    `weapon_stattrak`, `weapon_stattrak_count`, `weapon_nametag`' . $stickerCols . '
             FROM `' . self::skinsTable() . '` WHERE `steamid` = ?',
            [$steamid], self::DB
        );
    }

    /**
     * One-time data hygiene for a player's weapon skins: forced-team weapons
     * (e.g. AK = T-only) must have at most ONE row, on their forced team. Older
     * saves (before savePaintRow normalized this) could leave stray rows on the
     * wrong team — which the game plugin would then apply to the wrong side.
     * This drops every wrong-team row for forced-team weapons. Cheap: a single
     * DELETE scoped to this player and the fixed list of forced-team defindexes.
     * Returns the number of rows removed.
     */
    public static function normalizeForcedTeams(string $steamid): int
    {
        if (!Database::tableExists(self::skinsTable(), self::DB)) {
            return 0;
        }
        $map = WeaponPaintsData::forcedTeamWeapons();
        if (!$map) {
            return 0;
        }
        $removed = 0;
        foreach ($map as $defindex => $team) {
            // Delete any row for this forced-team weapon whose team is NOT the
            // forced team (covers both wrong-team rows and legacy TEAM_BOTH).
            $stmt = Database::run(
                'DELETE FROM `' . self::skinsTable() . '` WHERE `steamid` = ? AND `weapon_defindex` = ? AND `weapon_team` <> ?',
                [$steamid, (int) $defindex, (int) $team], self::DB
            );
            $removed += $stmt->rowCount();
        }
        return $removed;
    }

    /**
     * Data hygiene: a weapon that has a per-team (T=2 or CT=3) skin AND a stale
     * "Both" (team 1) row left over from the past is contradictory — the plugin
     * would then apply the old Both skin too. Drop the Both row whenever a
     * per-team row exists for the same weapon. Returns rows removed.
     */
    public static function normalizeStaleBoth(string $steamid): int
    {
        if (!Database::tableExists(self::skinsTable(), self::DB)) {
            return 0;
        }
        $stmt = Database::run(
            'DELETE b FROM `' . self::skinsTable() . '` b
             JOIN `' . self::skinsTable() . '` p
               ON p.`steamid` = b.`steamid` AND p.`weapon_defindex` = b.`weapon_defindex`
              AND p.`weapon_team` IN (2, 3)
             WHERE b.`steamid` = ? AND b.`weapon_team` = 1',
            [$steamid], self::DB
        );
        return $stmt->rowCount();
    }

    public static function playerKnives(string $steamid): array
    {
        if (!Database::tableExists(self::knifeTable(), self::DB)) {
            return [];
        }
        return Database::all('SELECT * FROM `' . self::knifeTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
    }

    public static function playerGloves(string $steamid): array
    {
        if (!Database::tableExists(self::glovesTable(), self::DB)) {
            return [];
        }
        return Database::all('SELECT * FROM `' . self::glovesTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
    }

    /** @return array{ct:?string,t:?string} */
    public static function playerAgents(string $steamid): array
    {
        if (!Database::tableExists(self::agentsTable(), self::DB)) {
            return ['ct' => null, 't' => null];
        }
        $row = Database::one(
            'SELECT `agent_ct`, `agent_t` FROM `' . self::agentsTable() . '` WHERE `steamid` = ?',
            [$steamid], self::DB
        );
        return [
            'ct' => ($row['agent_ct'] ?? null) ?: null,
            't' => ($row['agent_t'] ?? null) ?: null,
        ];
    }

    public static function playerMusic(string $steamid): array
    {
        if (!Database::tableExists(self::musicTable(), self::DB)) {
            return [];
        }
        return Database::all('SELECT * FROM `' . self::musicTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
    }

    public static function playerPins(string $steamid): array
    {
        if (!Database::tableExists(self::pinsTable(), self::DB)) {
            return [];
        }
        return Database::all('SELECT * FROM `' . self::pinsTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
    }

                                                                        
                                                                 
                                                                        

    /**
     * Apply one equip action from the posted `forma` string. Returns true when
     * the input was understood (regardless of whether rows actually changed).
     */
    public static function save(array $post, string $steamid): bool
    {
        if (!isset($post['forma']) || $steamid === '') {
            return false;
        }
        if (!Database::tableExists(self::skinsTable(), self::DB)) {
            return false;
        }

        $ex = explode('-', (string) $post['forma']);
        $team = isset($post['team']) ? (int) $post['team'] : WeaponPaintsData::TEAM_BOTH;
        if (!in_array($team, [WeaponPaintsData::TEAM_BOTH, WeaponPaintsData::TEAM_T, WeaponPaintsData::TEAM_CT], true)) {
            $team = WeaponPaintsData::TEAM_BOTH;
        }

        $knifeDefindexes = implode(',', WeaponPaintsData::KNIFE_DEFINDEXES);
        $gloveDefindexes = implode(',', WeaponPaintsData::GLOVE_DEFINDEXES);

        if (($ex[0] ?? '') === 'agent') {
            $agentTeam = isset($ex[1]) ? (int) $ex[1] : WeaponPaintsData::TEAM_CT;
            array_shift($ex); // 'agent'
            array_shift($ex); // team
            $modelPath = implode('-', $ex);
            if ($modelPath === 'null' || $modelPath === '') {
                $modelPath = null;
            }

            $existing = Database::all('SELECT `steamid` FROM `' . self::agentsTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
            if ($agentTeam === WeaponPaintsData::TEAM_CT) {
                if ($existing) {
                    Database::run('UPDATE `' . self::agentsTable() . '` SET `agent_ct` = ? WHERE `steamid` = ?', [$modelPath, $steamid], self::DB);
                } else {
                    Database::run('INSERT INTO `' . self::agentsTable() . '` (`steamid`, `agent_ct`, `agent_t`) VALUES (?, ?, NULL)', [$steamid, $modelPath], self::DB);
                }
            } elseif ($agentTeam === WeaponPaintsData::TEAM_T) {
                if ($existing) {
                    Database::run('UPDATE `' . self::agentsTable() . '` SET `agent_t` = ? WHERE `steamid` = ?', [$modelPath, $steamid], self::DB);
                } else {
                    Database::run('INSERT INTO `' . self::agentsTable() . '` (`steamid`, `agent_ct`, `agent_t`) VALUES (?, NULL, ?)', [$steamid, $modelPath], self::DB);
                }
            }
        } elseif (($ex[0] ?? '') === 'knife') {
            $knifeDefindex = (int) ($ex[1] ?? 0);
            $paintId = isset($ex[2]) ? (int) $ex[2] : null;
            $knifes = WeaponPaintsData::getKnifeTypes();

            if (array_key_exists($knifeDefindex, $knifes)) {
                if ($team === WeaponPaintsData::TEAM_BOTH) {
                    Database::run('DELETE FROM `' . self::knifeTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
                    Database::run('DELETE FROM `' . self::skinsTable() . '` WHERE `steamid` = ? AND `weapon_defindex` IN (' . $knifeDefindexes . ')', [$steamid], self::DB);
                } else {
                    $rows = Database::all('SELECT weapon_team FROM `' . self::knifeTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
                    foreach ($rows as $r) {
                        if ((int) $r['weapon_team'] === 1) {
                            $opp = ($team === WeaponPaintsData::TEAM_T) ? WeaponPaintsData::TEAM_CT : WeaponPaintsData::TEAM_T;
                            Database::run('UPDATE `' . self::knifeTable() . '` SET `weapon_team` = ? WHERE `steamid` = ? AND `weapon_team` = 1', [$opp, $steamid], self::DB);
                            Database::run('UPDATE `' . self::skinsTable() . '` SET `weapon_team` = ? WHERE `steamid` = ? AND `weapon_team` = 1 AND `weapon_defindex` IN (' . $knifeDefindexes . ')', [$opp, $steamid], self::DB);
                        }
                    }
                    Database::run('DELETE FROM `' . self::knifeTable() . '` WHERE `steamid` = ? AND `weapon_team` = ?', [$steamid, $team], self::DB);
                    Database::run('DELETE FROM `' . self::skinsTable() . '` WHERE `steamid` = ? AND `weapon_team` = ? AND `weapon_defindex` IN (' . $knifeDefindexes . ')', [$steamid, $team], self::DB);
                }

                Database::run(
                    'INSERT INTO `' . self::knifeTable() . '` (`steamid`, `knife`, `weapon_team`) VALUES (?, ?, ?)',
                    [$steamid, $knifes[$knifeDefindex]['weapon_name'], $team], self::DB
                );

                if ($knifeDefindex !== 0) {
                    self::savePaintRow($post, $steamid, $knifeDefindex, $paintId, WeaponPaintsData::skinsFromJson(), $team);
                }
            }
        } elseif (($ex[0] ?? '') === 'glove') {
            $gloveDefindex = (int) ($ex[1] ?? 0);
            $paintId = isset($ex[2]) ? (int) $ex[2] : null;
            $gloves = WeaponPaintsData::getGloveTypes();

            if (array_key_exists($gloveDefindex, $gloves)) {
                if ($team === WeaponPaintsData::TEAM_BOTH) {
                    Database::run('DELETE FROM `' . self::glovesTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
                    Database::run('DELETE FROM `' . self::skinsTable() . '` WHERE `steamid` = ? AND `weapon_defindex` IN (' . $gloveDefindexes . ')', [$steamid], self::DB);
                } else {
                    $rows = Database::all('SELECT weapon_team FROM `' . self::glovesTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
                    foreach ($rows as $r) {
                        if ((int) $r['weapon_team'] === 1) {
                            $opp = ($team === WeaponPaintsData::TEAM_T) ? WeaponPaintsData::TEAM_CT : WeaponPaintsData::TEAM_T;
                            Database::run('UPDATE `' . self::glovesTable() . '` SET `weapon_team` = ? WHERE `steamid` = ? AND `weapon_team` = 1', [$opp, $steamid], self::DB);
                            Database::run('UPDATE `' . self::skinsTable() . '` SET `weapon_team` = ? WHERE `steamid` = ? AND `weapon_team` = 1 AND `weapon_defindex` IN (' . $gloveDefindexes . ')', [$opp, $steamid], self::DB);
                        }
                    }
                    Database::run('DELETE FROM `' . self::glovesTable() . '` WHERE `steamid` = ? AND `weapon_team` = ?', [$steamid, $team], self::DB);
                    Database::run('DELETE FROM `' . self::skinsTable() . '` WHERE `steamid` = ? AND `weapon_team` = ? AND `weapon_defindex` IN (' . $gloveDefindexes . ')', [$steamid, $team], self::DB);
                }

                Database::run(
                    'INSERT INTO `' . self::glovesTable() . '` (`steamid`, `weapon_defindex`, `weapon_team`) VALUES (?, ?, ?)',
                    [$steamid, $gloveDefindex, $team], self::DB
                );

                if ($gloveDefindex !== 0) {
                    self::savePaintRow($post, $steamid, $gloveDefindex, $paintId, WeaponPaintsData::glovesFromJson(), $team);
                }
            }
        } elseif (($ex[0] ?? '') === 'music') {
            $musicId = (int) ($ex[1] ?? 0);
            if ($team === WeaponPaintsData::TEAM_BOTH) {
                Database::run('DELETE FROM `' . self::musicTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
            } else {
                $existing = Database::all('SELECT weapon_team FROM `' . self::musicTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
                foreach ($existing as $r) {
                    if ((int) $r['weapon_team'] === WeaponPaintsData::TEAM_BOTH) {
                        $opp = ($team === WeaponPaintsData::TEAM_T) ? WeaponPaintsData::TEAM_CT : WeaponPaintsData::TEAM_T;
                        Database::run('UPDATE `' . self::musicTable() . '` SET `weapon_team` = ? WHERE `steamid` = ? AND `weapon_team` = 1', [$opp, $steamid], self::DB);
                    }
                }
                Database::run('DELETE FROM `' . self::musicTable() . '` WHERE `steamid` = ? AND `weapon_team` = ?', [$steamid, $team], self::DB);
            }
            Database::run(
                'INSERT INTO `' . self::musicTable() . '` (`steamid`, `weapon_team`, `music_id`) VALUES (?, ?, ?)',
                [$steamid, $team, $musicId], self::DB
            );
        } elseif (($ex[0] ?? '') === 'pin') {
            $pinId = (int) ($ex[1] ?? 0);
            if ($team === WeaponPaintsData::TEAM_BOTH) {
                Database::run('DELETE FROM `' . self::pinsTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
            } else {
                $existing = Database::all('SELECT weapon_team FROM `' . self::pinsTable() . '` WHERE `steamid` = ?', [$steamid], self::DB);
                foreach ($existing as $r) {
                    if ((int) $r['weapon_team'] === WeaponPaintsData::TEAM_BOTH) {
                        $opp = ($team === WeaponPaintsData::TEAM_T) ? WeaponPaintsData::TEAM_CT : WeaponPaintsData::TEAM_T;
                        Database::run('UPDATE `' . self::pinsTable() . '` SET `weapon_team` = ? WHERE `steamid` = ? AND `weapon_team` = 1', [$opp, $steamid], self::DB);
                    }
                }
                Database::run('DELETE FROM `' . self::pinsTable() . '` WHERE `steamid` = ? AND `weapon_team` = ?', [$steamid, $team], self::DB);
            }
            Database::run(
                'INSERT INTO `' . self::pinsTable() . '` (`steamid`, `weapon_team`, `id`) VALUES (?, ?, ?)',
                [$steamid, $team, $pinId], self::DB
            );
        } else {
            $defindex = (int) ($ex[0] ?? 0);
            $paintId = (int) ($ex[1] ?? 0);

            $forcedTeam = WeaponPaintsData::getForcedTeam($defindex);
            if ($forcedTeam !== null) {
                $team = $forcedTeam;
            }

            $skins = WeaponPaintsData::skinsFromJson();
            if (isset($skins[$defindex][$paintId])) {
                self::savePaintRow($post, $steamid, $defindex, $paintId, $skins, $team);
            }
        }

        return true;
    }

    /**
     * DELETE/UPDATE/INSERT a single row in player_skins, mirroring the source
     * savePaintRow() exactly (team split, clamps: wear 0..1, seed 0..1000,
     * stattrak 0/1, count >=0, nametag mb_substr 32).
     */
    private static function savePaintRow(array $post, string $steamid, int $defindex, ?int $paintId, array $skinTable, int $team): void
    {
        $forcedTeam = WeaponPaintsData::getForcedTeam($defindex);
        if ($forcedTeam !== null) {
            // Forced-team weapon (e.g. AK = T-only): it can only ever have ONE
            // row, on the forced team. Wipe EVERY row for this weapon first —
            // this also cleans up any stray wrong-team rows left by older saves
            // before this normalization existed — then insert the single row.
            Database::run(
                'DELETE FROM `' . self::skinsTable() . '` WHERE `steamid` = ? AND `weapon_defindex` = ?',
                [$steamid, $defindex], self::DB
            );
            $team = $forcedTeam;
        } elseif ($team === WeaponPaintsData::TEAM_BOTH) {
            Database::run(
                'DELETE FROM `' . self::skinsTable() . '` WHERE `steamid` = ? AND `weapon_defindex` = ?',
                [$steamid, $defindex], self::DB
            );
        } else {
            $existing = Database::all(
                'SELECT weapon_team FROM `' . self::skinsTable() . '` WHERE `steamid` = ? AND `weapon_defindex` = ?',
                [$steamid, $defindex], self::DB
            );
            foreach ($existing as $row) {
                if ((int) $row['weapon_team'] === WeaponPaintsData::TEAM_BOTH) {
                    $opp = ($team === WeaponPaintsData::TEAM_T) ? WeaponPaintsData::TEAM_CT : WeaponPaintsData::TEAM_T;
                    Database::run(
                        'UPDATE `' . self::skinsTable() . '` SET `weapon_team` = ? WHERE `steamid` = ? AND `weapon_defindex` = ? AND `weapon_team` = 1',
                        [$opp, $steamid, $defindex], self::DB
                    );
                }
            }
            Database::run(
                'DELETE FROM `' . self::skinsTable() . '` WHERE `steamid` = ? AND `weapon_defindex` = ? AND `weapon_team` = ?',
                [$steamid, $defindex, $team], self::DB
            );
        }

        if (
            $paintId === null || !isset($skinTable[$defindex][$paintId])
            || !isset($post['wear']) || !is_numeric($post['wear'])
            || !isset($post['seed']) || !is_numeric($post['seed'])
        ) {
            return;
        }

        $wear = max(0.0, min(1.0, (float) $post['wear']));
        $seed = max(0, min(1000, (int) $post['seed']));
        $stattrak = !empty($post['stattrak']) ? 1 : 0;
        $stattrakCount = isset($post['stattrak_count']) && is_numeric($post['stattrak_count']) ? max(0, (int) $post['stattrak_count']) : 0;
        $nametag = isset($post['nametag']) && trim((string) $post['nametag']) !== ''
            ? mb_substr(trim((string) $post['nametag']), 0, 32)
            : null;

        // Stickers (5 slots) + keychain live inline on the skin row when the
        // columns exist. x/y = 0 lets the game use the default slot position;
        // any other value is a free placement picked in the 3D preview.
        //   weapon_sticker_N := "id;schema;x;y;wear;scale;rotation" (0 = empty)
        //   weapon_keychain  := "id;x;y;z;seed"                     (0 = empty)
        if (self::hasStickerColumns()) {
            $stickerIds = self::sanitizeStickerIds($post['stickers'] ?? []);
            $stickerPos = is_array($post['sticker_pos'] ?? null) ? $post['sticker_pos'] : [];
            $keychainId = isset($post['keychain']) && ctype_digit((string) $post['keychain']) ? (int) $post['keychain'] : 0;

            $stickerCols = [];
            $stickerVals = [];
            for ($i = 0; $i < self::STICKER_SLOTS; $i++) {
                $stickerCols[] = '`weapon_sticker_' . $i . '`';
                $id = $stickerIds[$i] ?? 0;
                if ($id > 0) {
                    [$x, $y, $r] = self::stickerPlacement($stickerPos[$i] ?? '');
                    $stickerVals[] = $id . ';0;' . $x . ';' . $y . ';0;0;' . $r;
                } else {
                    $stickerVals[] = '0;0;0;0;0;0;0';
                }
            }
            $keychainVal = $keychainId > 0 ? ($keychainId . ';0;0;0;0') : '0;0;0;0;0';

            $cols = '`steamid`, `weapon_defindex`, `weapon_paint_id`, `weapon_wear`, `weapon_seed`, `weapon_team`, `weapon_stattrak`, `weapon_stattrak_count`, `weapon_nametag`, '
                . implode(', ', $stickerCols) . ', `weapon_keychain`';
            $placeholders = rtrim(str_repeat('?, ', 9 + self::STICKER_SLOTS + 1), ', ');
            $params = array_merge(
                [$steamid, $defindex, $paintId, $wear, $seed, $team, $stattrak, $stattrakCount, $nametag],
                $stickerVals,
                [$keychainVal]
            );
            Database::run(
                'INSERT INTO `' . self::skinsTable() . '` (' . $cols . ') VALUES (' . $placeholders . ')',
                $params, self::DB
            );
            return;
        }

        Database::run(
            'INSERT INTO `' . self::skinsTable() . '` (`steamid`, `weapon_defindex`, `weapon_paint_id`, `weapon_wear`, `weapon_seed`, `weapon_team`, `weapon_stattrak`, `weapon_stattrak_count`, `weapon_nametag`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$steamid, $defindex, $paintId, $wear, $seed, $team, $stattrak, $stattrakCount, $nametag], self::DB
        );
    }

    /**
     * Normalize the posted sticker ids into a fixed array of STICKER_SLOTS
     * non-negative ints (0 = empty slot). Accepts stickers[] as a plain array
     * keyed 0..4; anything non-numeric becomes 0.
     * @return int[]
     */
    private static function sanitizeStickerIds(mixed $raw): array
    {
        $out = array_fill(0, self::STICKER_SLOTS, 0);
        if (is_array($raw)) {
            for ($i = 0; $i < self::STICKER_SLOTS; $i++) {
                $v = $raw[$i] ?? 0;
                $out[$i] = (is_numeric($v) && (int) $v > 0) ? (int) $v : 0;
            }
        }
        return $out;
    }

    /**
     * Posted "x;y;rotation" for one sticker slot, clamped and formatted with a
     * dot decimal separator (the plugin parses with InvariantCulture).
     * @return array{0:string,1:string,2:string}
     */
    private static function stickerPlacement(mixed $raw): array
    {
        $p = explode(';', is_string($raw) ? $raw : '');
        $num = static function (mixed $v, float $min, float $max, int $dp): string {
            $f = is_numeric($v) ? max($min, min($max, (float) $v)) : 0.0;
            $s = rtrim(rtrim(number_format($f, $dp, '.', ''), '0'), '.');
            return $s === '' || $s === '-0' ? '0' : $s;
        };
        return [$num($p[0] ?? 0, -1.0, 1.0, 4), $num($p[1] ?? 0, -1.0, 1.0, 4), $num($p[2] ?? 0, -360.0, 360.0, 1)];
    }

    /**
     * Parse a stored sticker/keychain column value ("id;...") into just its
     * item id (0 when empty/malformed).
     */
    public static function parseSlotId(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $first = explode(';', (string) $value)[0];
        return ctype_digit($first) ? (int) $first : 0;
    }

    /**
     * Parse a sticker column ("id;schema;x;y;wear;scale;rotation") OR keychain
     * ("id;x;y;z;seed") into ['id','x','y','r']. For stickers x/y are fields 2,3
     * and rotation is field 6; for keychains x/y are fields 1,2. 0/empty => id 0.
     * @return array{id:int,x:float,y:float,r:float}
     */
    public static function parseSlot(mixed $value, bool $keychain = false): array
    {
        $out = ['id' => 0, 'x' => 0.0, 'y' => 0.0, 'r' => 0.0];
        if ($value === null || $value === '') {
            return $out;
        }
        $p = explode(';', (string) $value);
        $id = isset($p[0]) && ctype_digit($p[0]) ? (int) $p[0] : 0;
        if ($id <= 0) {
            return $out;
        }
        $out['id'] = $id;
        if ($keychain) {
            $out['x'] = isset($p[1]) && is_numeric($p[1]) ? (float) $p[1] : 0.0;
            $out['y'] = isset($p[2]) && is_numeric($p[2]) ? (float) $p[2] : 0.0;
        } else {
            $out['x'] = isset($p[2]) && is_numeric($p[2]) ? (float) $p[2] : 0.0;
            $out['y'] = isset($p[3]) && is_numeric($p[3]) ? (float) $p[3] : 0.0;
            $out['r'] = isset($p[6]) && is_numeric($p[6]) ? (float) $p[6] : 0.0;
        }
        return $out;
    }

}

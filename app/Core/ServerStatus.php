<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Parser for the RCON "status" output of a CS2 / Source server.
 *
 * Both the legacy Source layout and the CS2 layout are handled, because
 * the columns differ between builds:
 *   #  2 1  "Player" STEAM_1:1:123 05:12 45 0 active 786432 1.2.3.4:27005
 *   #   2 "Player"   [U:1:246]     1.2.3.4:27005 active 786432
 *   2    00:32   43    0     active 786432 1.2.3.4:27005 'Player'   (CS2, "players" section)
 */
final class ServerStatus
{
    /**
     * @return array<int,array{userid:int,name:string,steamid:string,steamid64:string,time:string,ping:int,bot:bool}>
     */
    public static function parse(string $raw): array
    {
        $players = [];
        $inCs2Players = false;
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // CS2 layout:
            // ---------players--------
            //   id     time ping loss      state   rate adr name
            //    2    00:32   43    0     active 786432 1.2.3.4:27005 'Player'
            // #end
            if (preg_match('/^-+\s*players\s*-+$/i', $line)) {
                $inCs2Players = true;
                continue;
            }
            if ($inCs2Players) {
                if ($line[0] === '#' || $line[0] === '-') {
                    $inCs2Players = false;
                    continue;
                }
                $player = self::parseCs2Line($line);
                if ($player !== null) {
                    $players[] = $player;
                }
                continue;
            }
            if ($line[0] !== '#') {
                continue;
            }
            // Header row ("# userid name uniqueid ...") has no quoted name.
            if (!preg_match('/"([^"]*)"/', $line, $nameMatch)) {
                continue;
            }
            // The userid is the first number after the leading '#'.
            if (!preg_match('/^#\s*(\d+)/', $line, $idMatch)) {
                continue;
            }

            $steamId   = '';
            $steamId64 = '';
            if (preg_match('/(STEAM_[0-5]:[01]:\d+|\[U:1:\d+\]|7656119\d{10})/', $line, $sid)) {
                $steamId   = $sid[1];
                $steamId64 = Steam::toSteamId64Any($steamId) ?? '';
            }

            // Connected time, when the build prints it (mm:ss or hh:mm:ss).
            $time = '';
            if (preg_match('/\b(\d{1,3}:\d{2}(?::\d{2})?)\b/', $line, $tm)) {
                $time = $tm[1];
            }

            // Ping: the first standalone small number after the time column.
            $ping = 0;
            $tail = $time !== '' ? (string) strstr($line, $time) : '';
            if ($tail !== '' && preg_match('/\b(\d{1,4})\b/', substr($tail, strlen($time)), $pg)) {
                $ping = (int) $pg[1];
            }

            $players[] = [
                'userid'    => (int) $idMatch[1],
                'name'      => $nameMatch[1],
                'steamid'   => $steamId,
                'steamid64' => $steamId64,
                'time'      => $time,
                'ping'      => $ping,
                'bot'       => $steamId === '' && stripos($line, 'BOT') !== false,
            ];
        }
        return $players;
    }

    /** Slot count from the "players : 1 humans, 0 bots (20 max)" line, or null. */
    public static function maxPlayers(string $raw): ?int
    {
        if (preg_match('/^\s*players\s*:.*?\((\d+)(?:\/\d+)?\s*max\)/mi', $raw, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Fill missing SteamIDs from CS2 "status_json" output. Players are matched by
     * userid when the JSON provides it, otherwise only by a name that is unique
     * on both sides, so an ambiguous match never attaches the wrong identity.
     */
    public static function enrichFromJson(array $players, string $raw): array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return $players;
        }
        $data = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            return $players;
        }

        $clients = [];
        $collect = static function (array $node) use (&$collect, &$clients): void {
            foreach ($node as $value) {
                if (!is_array($value)) {
                    continue;
                }
                if (isset($value['name']) && is_string($value['name'])) {
                    $sid = '';
                    foreach (['steamid64', 'steamid', 'steam_id', 'xuid', 'accountid'] as $key) {
                        if (isset($value[$key]) && (is_string($value[$key]) || is_int($value[$key]))) {
                            $sid = Steam::toSteamId64Any((string) $value[$key]) ?? '';
                            if ($sid !== '') {
                                break;
                            }
                        }
                    }
                    if ($sid !== '') {
                        $userId = null;
                        foreach (['userid', 'user_id'] as $key) {
                            if (isset($value[$key]) && is_numeric($value[$key])) {
                                $userId = (int) $value[$key];
                                break;
                            }
                        }
                        $clients[] = ['name' => $value['name'], 'steamid64' => $sid, 'userid' => $userId];
                    }
                }
                $collect($value);
            }
        };
        $collect($data);
        if (!$clients) {
            return $players;
        }

        $nameCount = static function (array $rows): array {
            $counts = [];
            foreach ($rows as $row) {
                $counts[$row['name']] = ($counts[$row['name']] ?? 0) + 1;
            }
            return $counts;
        };
        $playerNames = $nameCount($players);
        $clientNames = $nameCount($clients);

        foreach ($players as &$player) {
            if ($player['steamid64'] !== '' || $player['bot']) {
                continue;
            }
            foreach ($clients as $client) {
                $byId = $client['userid'] !== null && $client['userid'] === $player['userid'];
                $byName = $client['userid'] === null && $client['name'] === $player['name']
                    && $playerNames[$player['name']] === 1 && $clientNames[$client['name']] === 1;
                if ($byId || $byName) {
                    $player['steamid64'] = $client['steamid64'];
                    $player['steamid'] = $client['steamid64'];
                    break;
                }
            }
        }
        unset($player);
        return $players;
    }

    private static function parseCs2Line(string $line): ?array
    {
        if (!preg_match("/^(\d+)\s+(.*?)\s*'(.*)'$/", $line, $m)) {
            return null;
        }
        $userId = (int) $m[1];
        $columns = preg_split('/\s+/', trim($m[2])) ?: [];
        $time = $columns[0] ?? '';
        $state = strtolower($columns[3] ?? '');
        // Reserved/placeholder slots (65535, [NoChan], challenging) are not players.
        if ($userId >= 65535 || $time === '' || $time[0] === '[' || $state === 'challenging') {
            return null;
        }
        $bot = in_array('BOT', array_map('strtoupper', $columns), true);
        return [
            'userid'    => $userId,
            'name'      => $m[3],
            'steamid'   => '',
            'steamid64' => '',
            'time'      => preg_match('/^\d{1,3}:\d{2}(?::\d{2})?$/', $time) ? $time : '',
            'ping'      => isset($columns[1]) && ctype_digit($columns[1]) ? (int) $columns[1] : 0,
            'bot'       => $bot,
        ];
    }
}

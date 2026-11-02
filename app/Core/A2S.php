<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal A2S (Source server query) client over UDP.
 *
 * Fetches live server info via the A2S_INFO request: hostname, current map,
 * player count and max players. CS2 / modern Source servers reply to the first
 * A2S_INFO with an S2C_CHALLENGE that must be echoed back, so we handle that
 * one-shot retry transparently.
 *
 * Queries have a bounded UDP wait and a short shared cache. A failed query
 * means the status is unavailable, not that the game server is certainly offline.
 */
final class A2S
{
    private const A2S_INFO_HEADER = "\xFF\xFF\xFF\xFFTSource Engine Query\x00";
    private const S2C_CHALLENGE   = 0x41; // 'A'
    private const A2S_INFO_REPLY  = 0x49; // 'I'
    private const UNAVAILABLE = [
        'online' => false, 'name' => null, 'map' => null,
        'players' => null, 'max_players' => null, 'bots' => null,
    ];
    private const CACHE_SECONDS = 15;
    private const FAILURE_CACHE_SECONDS = 3;

    /** @return array{0:string,1:int} */
    public static function endpoint(string $address): array
    {
        $address = trim($address);
        if ($address === '') return ['', 0];
        $parts = parse_url('udp://' . $address);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? 27015) : 0;
        if ($host === '' || $port < 1 || $port > 65535
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['path'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])$/i', $host)) {
            error_log('[CS2Panel] Invalid configured server address.');
            return ['', 0];
        }
        return [$host, $port];
    }

    public static function connectUrl(string $address): string
    {
        [$host, $port] = self::endpoint($address);
        return $host !== '' ? 'steam://connect/' . $host . ':' . $port : '';
    }

    /**
     * Query a single server.
     *
     * @param string $host
     * @param int    $port  game (query) port
     * @param float  $timeout seconds
     * @return array{
     *   online:bool, name:?string, map:?string, players:?int,
     *   max_players:?int, bots:?int
     * }
     */
    public static function info(string $host, int $port, float $timeout = 1.0): array
    {
        if ($host === '' || $port <= 0 || $port > 65535 || !is_finite($timeout) || $timeout <= 0) {
            return self::unavailable($host, $port, 'Invalid query endpoint or timeout.');
        }
        $directory = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'cs2panel-query-' . substr(hash('sha256', BASE_PATH), 0, 16);
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory))) {
            error_log('[CS2Panel] A2S cache directory unavailable; querying directly.');
            return self::query($host, $port, $timeout);
        }
        $file = $directory . DIRECTORY_SEPARATOR . hash('sha256', $host . ':' . $port) . '.json';
        if (is_link($file)) {
            error_log('[CS2Panel] A2S cache path is a link; querying directly.');
            return self::query($host, $port, $timeout);
        }
        $cache = @fopen($file, 'c+b');
        if ($cache === false) {
            error_log('[CS2Panel] A2S cache file unavailable; querying directly.');
            return self::query($host, $port, $timeout);
        }
        $locked = false;
        try {
            $lockDeadline = microtime(true) + $timeout + .25;
            do {
                $wouldBlock = 0;
                $locked = @flock($cache, LOCK_EX | LOCK_NB, $wouldBlock);
                if ($locked) break;
                if (!$wouldBlock) {
                    error_log('[CS2Panel] A2S cache locking unavailable; querying directly.');
                    return self::query($host, $port, $timeout);
                }
                usleep(10000);
            } while (microtime(true) < $lockDeadline);
            if (!$locked) {
                error_log('[CS2Panel] A2S cache lock timed out; querying directly.');
                return self::query($host, $port, $timeout);
            }
            rewind($cache);
            $raw = stream_get_contents($cache, 32769);
            if ($raw === false) {
                error_log('[CS2Panel] A2S cached snapshot could not be read.');
            } elseif ($raw !== '') {
                $snapshot = json_decode($raw, true);
                $data = is_array($snapshot) ? ($snapshot['data'] ?? null) : null;
                $valid = strlen($raw) <= 32768 && is_array($data)
                    && ($snapshot['version'] ?? null) === 1 && is_int($snapshot['time'] ?? null)
                    && is_numeric($snapshot['timeout'] ?? null) && is_bool($data['online'] ?? null);
                foreach (['name', 'map'] as $key) {
                    $valid = $valid && is_array($data) && array_key_exists($key, $data)
                        && ($data[$key] === null || is_string($data[$key]));
                }
                foreach (['players', 'max_players', 'bots'] as $key) {
                    $valid = $valid && is_array($data) && array_key_exists($key, $data)
                        && ($data[$key] === null || (is_int($data[$key]) && $data[$key] >= 0));
                }
                if ($valid) {
                    $age = time() - $snapshot['time'];
                    $ttl = $data['online'] ? self::CACHE_SECONDS : self::FAILURE_CACHE_SECONDS;
                    if ($age >= 0 && $age < $ttl && ($data['online'] || $timeout <= (float) $snapshot['timeout'])) {
                        return $data;
                    }
                } else {
                    error_log('[CS2Panel] A2S cached snapshot is invalid; refreshing it.');
                }
            }
            $data = self::query($host, $port, $timeout);
            try {
                $json = json_encode(['version' => 1, 'time' => time(), 'timeout' => $timeout, 'data' => $data], JSON_THROW_ON_ERROR);
                if (!rewind($cache) || fwrite($cache, $json) !== strlen($json) || !ftruncate($cache, strlen($json)) || !fflush($cache)) {
                    error_log('[CS2Panel] A2S cached snapshot could not be saved completely.');
                    ftruncate($cache, 0);
                }
            } catch (\JsonException $exception) {
                error_log('[CS2Panel] A2S cached snapshot encoding failed: ' . $exception->getMessage());
            }
            return $data;
        } finally {
            if ($locked) flock($cache, LOCK_UN);
            fclose($cache);
        }
    }

    private static function unavailable(string $host, int $port, string $reason): array
    {
        error_log('[CS2Panel] A2S ' . $host . ':' . $port . ': ' . $reason);
        return self::UNAVAILABLE;
    }

    private static function query(string $host, int $port, float $timeout): array
    {
        if (!function_exists('fsockopen')) {
            return self::unavailable($host, $port, 'UDP socket support is unavailable.');
        }
        $deadline = microtime(true) + $timeout;
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen('udp://' . $host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            return self::unavailable($host, $port, 'UDP connection failed: ' . $errstr);
        }
        try {
            if (!stream_set_blocking($fp, false)) {
                return self::unavailable($host, $port, 'Cannot configure the UDP socket.');
            }
            $data = null;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) break;
                if (@fwrite($fp, self::A2S_INFO_HEADER) !== strlen(self::A2S_INFO_HEADER)) {
                    return self::unavailable($host, $port, 'A2S request could not be written.');
                }
                // Reserve time for one resend if the first datagram is lost.
                $data = self::readPacket($fp, $attempt === 0 ? $remaining * .55 : $remaining);
                if ($data !== null) break;
            }

            // Modern servers answer with a challenge that must be echoed back.
            if ($data !== null && substr($data, 0, 4) === "\xFF\xFF\xFF\xFF" && isset($data[4]) && ord($data[4]) === self::S2C_CHALLENGE) {
                if (strlen($data) < 9) {
                    return self::unavailable($host, $port, 'Incomplete A2S challenge.');
                }
                $challenge = substr($data, 5, 4);
                $request = self::A2S_INFO_HEADER . $challenge;
                if (@fwrite($fp, $request) !== strlen($request)) {
                    return self::unavailable($host, $port, 'A2S challenge reply could not be written.');
                }
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0.0) {
                    return self::unavailable($host, $port, 'A2S challenge exceeded the query budget.');
                }
                $data = self::readPacket($fp, $remaining);
            }

            if ($data === null) {
                return self::unavailable($host, $port, 'No A2S response within the query budget.');
            }
            if (strlen($data) < 6 || substr($data, 0, 4) !== "\xFF\xFF\xFF\xFF" || ord($data[4]) !== self::A2S_INFO_REPLY) {
                return self::unavailable($host, $port, 'Unexpected A2S response.');
            }

            return self::parseInfo(substr($data, 5)) + ['online' => true];
        } catch (\RuntimeException $exception) {
            return self::unavailable($host, $port, $exception->getMessage());
        } finally {
            @fclose($fp);
        }
    }

    /**
     * Read one UDP datagram (single-packet responses only, which A2S_INFO is).
     * Uses stream_select to enforce a hard timeout — on a UDP socket a plain
     * fread() can otherwise block far longer than stream_set_timeout implies.
     */
    private static function readPacket($fp, float $timeout): ?string
    {
        if ($timeout <= 0) return null;
        $read = [$fp];
        $write = null;
        $except = null;
        $sec = (int) $timeout;
        $usec = (int) (($timeout - $sec) * 1_000_000);

        $ready = @stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false) {
            throw new \RuntimeException('UDP response wait failed.');
        }
        if ($ready === 0) {
            return null; // timed out or error, no data available
        }

        $data = @fread($fp, 65535);
        if ($data === false || $data === '') {
            return null;
        }
        return $data;
    }

    /**
     * Parse the A2S_INFO reply body (after the 'I' header byte).
     * Layout: Protocol(byte) Name(str) Map(str) Folder(str) Game(str)
     *         AppID(short) Players(byte) MaxPlayers(byte) Bots(byte) ...
     * @return array{name:?string, map:?string, players:?int, max_players:?int, bots:?int}
     */
    private static function parseInfo(string $body): array
    {
        $offset = 0;
        // Protocol byte.
        $offset += 1;

        $name = self::readString($body, $offset);
        $map  = self::readString($body, $offset);
        self::readString($body, $offset); // Folder
        self::readString($body, $offset); // Game

        // AppID (short, 2 bytes)
        if ($offset + 5 > strlen($body) || preg_match('//u', $name) !== 1 || preg_match('//u', $map) !== 1) {
            throw new \RuntimeException('Incomplete or invalid A2S info.');
        }
        $offset += 2;

        $players    = self::readByte($body, $offset);
        $maxPlayers = self::readByte($body, $offset);
        $bots       = self::readByte($body, $offset);

        return [
            'name'        => $name !== '' ? $name : null,
            'map'         => $map !== '' ? $map : null,
            'players'     => $players,
            'max_players' => $maxPlayers,
            'bots'        => $bots,
        ];
    }

    /** Read a null-terminated UTF-8 string, advancing $offset past the null. */
    private static function readString(string $buf, int &$offset): string
    {
        $end = strpos($buf, "\x00", $offset);
        if ($end === false) {
            throw new \RuntimeException('Incomplete A2S text field.');
        }
        $str = substr($buf, $offset, $end - $offset);
        $offset = $end + 1;
        return $str;
    }

    /** Read a single unsigned byte, advancing $offset. Null if past the end. */
    private static function readByte(string $buf, int &$offset): ?int
    {
        if (!isset($buf[$offset])) {
            return null;
        }
        $val = ord($buf[$offset]);
        $offset += 1;
        return $val;
    }
}

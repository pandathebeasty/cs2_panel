<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Settings;

/**
 * Routes statistics and contest operations to the selected K4-System or
 * K4-Zenith backend. Both return the same row shapes and play-time units.
 */
final class StatsBackend
{
    /** Which backend is active ('zenith' | 'k4system'). Defaults to zenith. */
    public static function which(): string
    {
        $b = strtolower(trim((string) Settings::get('stats_backend', (string) Config::get('stats.backend', 'zenith'))));
        return $b === 'k4system' ? 'k4system' : 'zenith';
    }

    public static function isK4System(): bool
    {
        return self::which() === 'k4system';
    }

                                                                        
    // Availability + leaderboards
                                                                        

    public static function enabled(): bool
    {
        if (!Settings::bool('stats_enabled', (bool) Config::get('stats.enabled', true))) {
            return false;
        }
        return self::isK4System() ? K4SystemModel::enabled() : ZenithModel::enabled();
    }

    public static function ranksLeaderboard(int $page, int $perPage, string $sort = 'points', string $dir = 'desc', string $search = ''): ?\App\Core\Paginator
    {
        return self::isK4System()
            ? K4SystemModel::ranksLeaderboard($page, $perPage, $sort, $dir, $search)
            : ZenithModel::ranksLeaderboard($page, $perPage, $sort, $dir, $search);
    }

    public static function playtimeLeaderboard(int $page, int $perPage, string $sort = 'playtime', string $dir = 'desc', string $search = ''): ?\App\Core\Paginator
    {
        return self::isK4System()
            ? K4SystemModel::playtimeLeaderboard($page, $perPage, $sort, $dir, $search)
            : ZenithModel::playtimeLeaderboard($page, $perPage, $sort, $dir, $search);
    }

    public static function statsLeaderboard(int $page, int $perPage, string $search = ''): ?\App\Core\Paginator
    {
        return self::isK4System()
            ? K4SystemModel::statsLeaderboard($page, $perPage, $search)
            : ZenithModel::statsLeaderboard($page, $perPage, $search);
    }

                                                                        
    // Resets (single player)
                                                                        

    public static function resetRank(string $steamId): bool
    {
        return self::isK4System() ? K4SystemModel::resetRank($steamId) : ZenithModel::resetRank($steamId);
    }

    public static function resetStats(string $steamId): bool
    {
        return self::isK4System() ? K4SystemModel::resetStats($steamId) : ZenithModel::resetStats($steamId);
    }

    public static function resetPlaytime(string $steamId): bool
    {
        return self::isK4System() ? K4SystemModel::resetPlaytime($steamId) : ZenithModel::resetPlaytime($steamId);
    }

                                                                        
    // Resets (everyone)
                                                                        

    public static function resetAllRanks(): int
    {
        return self::isK4System() ? K4SystemModel::resetAllRanks() : ZenithModel::resetAllRanks();
    }

    public static function resetAllStats(): int
    {
        return self::isK4System() ? K4SystemModel::resetAllStats() : ZenithModel::resetAllStats();
    }

    public static function resetAllPlaytime(): int
    {
        return self::isK4System() ? K4SystemModel::resetAllPlaytime() : ZenithModel::resetAllPlaytime();
    }

                                                                        
    // Admin edits
                                                                        

    public static function setPoints(string $steamId, int $points): bool
    {
        return self::isK4System() ? K4SystemModel::setPoints($steamId, $points) : ZenithModel::setPoints($steamId, $points);
    }

    /** @param array<string,int> $fields */
    public static function setStats(string $steamId, array $fields): bool
    {
        return self::isK4System() ? K4SystemModel::setStats($steamId, $fields) : ZenithModel::setStats($steamId, $fields);
    }

    public static function setPlaytime(string $steamId, int $minutes): bool
    {
        return self::isK4System() ? K4SystemModel::setPlaytime($steamId, $minutes) : ZenithModel::setPlaytime($steamId, $minutes);
    }

                                                                        
    // Dashboard + contests
                                                                        

    /** @return array<int, array<string, mixed>> */
    public static function top(int $limit = 5, ?string $steamId = null): array
    {
        return self::isK4System() ? K4SystemModel::top($limit, $steamId) : ZenithModel::top($limit, $steamId);
    }

    public static function playerRank(string $steamId): ?array
    {
        return self::top(1, $steamId)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function topByPlaytime(int $limit = 5, string $source = 'all'): array
    {
        return self::isK4System() ? K4SystemModel::topByPlaytime($limit, $source) : ZenithModel::topByPlaytime($limit, $source);
    }

    /** @return array<int, array<string, mixed>> */
    public static function topByMetric(string $metric, int $limit = 5, string $playtimeSource = 'all', int $offset = 0): array
    {
        return self::isK4System()
            ? K4SystemModel::topByMetric($metric, $limit, $playtimeSource, $offset)
            : ZenithModel::topByMetric($metric, $limit, $playtimeSource, $offset);
    }

    /**
     * Most-recently-active players (newest first), for the dashboard.
     * @return array<int, array<string, mixed>>
     */
    public static function recentActive(int $limit = 5): array
    {
        return self::isK4System() ? K4SystemModel::recentActive($limit) : ZenithModel::recentActive($limit);
    }

    /**
     * Players active per hour over the last $hours (oldest -> newest), for the
     * dashboard activity graph.
     * @return array<int, array{label:string, count:int}>
     */
    public static function activityByHour(int $hours = 6): array
    {
        return self::isK4System() ? K4SystemModel::activityByHour($hours) : ZenithModel::activityByHour($hours);
    }

    /**
     * SteamID64s active since a unix timestamp (lastseen >= $sinceTs), used by
     * the activity-history sampler.
     * @return string[]
     */
    public static function activeSince(int $sinceTs): array
    {
        return self::isK4System() ? K4SystemModel::activeSince($sinceTs) : ZenithModel::activeSince($sinceTs);
    }

    /** @return array{total:int,active:int} */
    public static function stats(): array
    {
        return self::isK4System() ? K4SystemModel::stats() : ZenithModel::stats();
    }
}

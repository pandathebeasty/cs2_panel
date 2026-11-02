<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Settings;
use App\Core\Steam;

final class ChatLogModel
{
    public const PER_PAGE_OPTIONS = [10, 25, 50];
    public const TEAMS = ['unassigned', 'spec', 't', 'ct'];
    public const CHANNELS = ['public', 'team'];

    public static function enabled(): bool
    {
        return Settings::bool('chat_logs_enabled', false);
    }

    public static function isValidTableName(string $table): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) === 1;
    }

    private static function table(): string
    {
        $table = (string) Settings::get('chat_logs_table', 'chat_logs');
        if (!self::isValidTableName($table)) {
            throw new \InvalidArgumentException('The Chat Logger table name is invalid.');
        }
        return $table;
    }

    public static function serverIds(): array
    {
        $table = self::table();
        $rows = Database::all("SELECT DISTINCT `server_id` FROM `{$table}` WHERE `server_id` <> '' ORDER BY `server_id` ASC");
        return array_map(static fn(array $row): string => (string) $row['server_id'], $rows);
    }

    public static function paginate(array $filters, int $page, int $perPage): Paginator
    {
        $table = self::table();
        $conditions = [];
        $params = [];

        $search = (string) ($filters['search'] ?? '');
        if ($search !== '') {
            // Treat wildcard characters as text, not as a request for the whole log.
            $needle = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $variants = Steam::identifierVariants($search);
            $conditions[] = "(`player_name` LIKE ? ESCAPE '!' OR `steam_id` LIKE ? ESCAPE '!' OR `message` LIKE ? ESCAPE '!' OR `date` LIKE ? ESCAPE '!'"
                . ($variants ? ' OR `steam_id` IN (' . implode(', ', array_fill(0, count($variants), '?')) . '))' : ')');
            array_push($params, $needle, $needle, $needle, $needle);
            array_push($params, ...$variants);
        }
        $serverId = (string) ($filters['server_id'] ?? '');
        if ($serverId !== '') {
            $conditions[] = '`server_id` = ?';
            $params[] = $serverId;
        }
        $team = (string) ($filters['team'] ?? '');
        if ($team !== '') {
            $flags = match ($team) {
                'unassigned' => '0, 1',
                'spec' => '2, 3',
                't' => '4, 5',
                'ct' => '6, 7',
                default => throw new \InvalidArgumentException('The Chat Logger team filter is invalid.'),
            };
            $conditions[] = "`where` IN ({$flags})";
        }
        $channel = (string) ($filters['channel'] ?? '');
        if ($channel !== '') {
            $flags = match ($channel) {
                'public' => '0, 2, 4, 6',
                'team' => '1, 3, 5, 7',
                default => throw new \InvalidArgumentException('The Chat Logger channel filter is invalid.'),
            };
            $conditions[] = "`where` IN ({$flags})";
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            throw new \InvalidArgumentException('The Chat Logger page size is invalid.');
        }
        return Paginator::make(
            "SELECT `id`, `date`, `map_name`, `steam_id`, `player_name`, `where`, `message`, `server_id` FROM `{$table}`{$where}",
            "SELECT COUNT(*) FROM `{$table}`{$where}",
            $params,
            $page,
            $perPage,
            '`id` DESC'
        );
    }

    public static function chatType(int $flag): array
    {
        if ($flag < 0 || $flag > 7) {
            return ['team' => 'unknown', 'channel' => 'unknown'];
        }
        // The plugin stores (TeamNum << 1) | TeamChat.
        return [
            'team' => self::TEAMS[$flag >> 1],
            'channel' => ($flag & 1) === 1 ? 'team' : 'public',
        ];
    }
}

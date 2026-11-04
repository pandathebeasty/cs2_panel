<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Efficient pagination: counts the total with a separate COUNT and fetches only the current page
 * with LIMIT/OFFSET. Never loads the whole table into memory.
 */
final class Paginator
{
    public array $items = [];
    public int $total = 0;
    public int $page = 1;
    public int $perPage = 25;
    public int $lastPage = 1;

    /**
     * @param string $baseSql   SELECT ... FROM ... WHERE ... (no LIMIT and no ORDER)
     * @param string $countSql  SELECT COUNT(*) FROM ... WHERE ... (same conditions)
     * @param array  $params    bound parameters for both queries
     * @param string $orderBy   ex: 'created DESC'
     */
    public static function make(
        string $baseSql,
        string $countSql,
        array $params,
        int $page,
        int $perPage,
        string $orderBy = '',
        ?string $connection = null
    ): self {
        $p = new self();
        $p->perPage = max(1, min(200, $perPage));
        $p->total   = self::count($countSql, $params, $connection);
        $p->lastPage = max(1, (int) ceil($p->total / $p->perPage));
        $p->page    = max(1, min($page, $p->lastPage));

        $offset = ($p->page - 1) * $p->perPage;
        $order  = $orderBy !== '' ? " ORDER BY {$orderBy}" : '';
        // LIMIT/OFFSET interpolated as int (cannot be bound as a parameter on all drivers)
        $sql = $baseSql . $order . ' LIMIT ' . $p->perPage . ' OFFSET ' . $offset;

        $p->items = Database::all($sql, $params, $connection);
        return $p;
    }

    /**
     * Like make(), but filters by player name / SteamID when $search is set. The base query must
     * expose `steam_id` and `name` aliases and $orderBy must only use aliases of the base SELECT.
     * Matching rows keep their real leaderboard position in `pos` (window function, MySQL 8 /
     * MariaDB 10.2+); older servers fall back to a plain filtered list without `pos`.
     */
    public static function filtered(
        string $baseSql,
        string $countSql,
        array $params,
        int $page,
        int $perPage,
        string $orderBy,
        ?string $connection,
        string $search
    ): self {
        $search = trim($search);
        if ($search === '') {
            return self::make($baseSql, $countSql, $params, $page, $perPage, $orderBy, $connection);
        }

        $like = '%' . addcslashes($search, '%_\\') . '%';
        $where = '(b.`name` LIKE ? OR CAST(b.`steam_id` AS CHAR) LIKE ?)';
        $extra = [$like, $like];
        $id = Steam::toSteamId64Any($search);
        if ($id !== null) {
            $where = '(' . $where . ' OR b.`steam_id` = ?)';
            $extra[] = $id;
        }
        $all = array_merge($params, $extra);
        $order = $orderBy !== '' ? $orderBy : '1';

        $p = new self();
        $p->perPage = max(1, min(200, $perPage));
        $p->total = (int) Database::scalar("SELECT COUNT(*) FROM ({$baseSql}) b WHERE {$where}", $all, $connection);
        $p->lastPage = max(1, (int) ceil($p->total / $p->perPage));
        $p->page = max(1, min($page, $p->lastPage));
        $limit = ' LIMIT ' . $p->perPage . ' OFFSET ' . (($p->page - 1) * $p->perPage);

        try {
            $p->items = Database::all(
                "SELECT * FROM (SELECT b.*, ROW_NUMBER() OVER (ORDER BY {$order}) AS pos FROM ({$baseSql}) b) b WHERE {$where} ORDER BY b.pos{$limit}",
                $all,
                $connection
            );
        } catch (\Throwable $e) {
            $p->items = Database::all("SELECT * FROM ({$baseSql}) b WHERE {$where} ORDER BY {$order}{$limit}", $all, $connection);
        }
        return $p;
    }

    /**
     * COUNT(*) on large tables is the slowest part of a list page. Totals of
     * 5 000+ rows are reused for 30 seconds; smaller ones are always exact.
     */
    private static function count(string $countSql, array $params, ?string $connection = null): int
    {
        $key = 'count:' . sha1(($connection ?? '') . "\0" . $countSql . "\0" . serialize($params));
        $cached = Cache::get($key);
        if (is_int($cached)) {
            return $cached;
        }
        $total = (int) Database::scalar($countSql, $params, $connection);
        if ($total >= 5000) {
            Cache::set($key, $total, 30);
        }
        return $total;
    }
}

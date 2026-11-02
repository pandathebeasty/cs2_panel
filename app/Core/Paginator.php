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
        string $orderBy = ''
    ): self {
        $p = new self();
        $p->perPage = max(1, min(200, $perPage));
        $p->total   = (int) Database::scalar($countSql, $params);
        $p->lastPage = max(1, (int) ceil($p->total / $p->perPage));
        $p->page    = max(1, min($page, $p->lastPage));

        $offset = ($p->page - 1) * $p->perPage;
        $order  = $orderBy !== '' ? " ORDER BY {$orderBy}" : '';
        // LIMIT/OFFSET interpolated as int (cannot be bound as a parameter on all drivers)
        $sql = $baseSql . $order . ' LIMIT ' . $p->perPage . ' OFFSET ' . $offset;

        $p->items = Database::all($sql, $params);
        return $p;
    }
}

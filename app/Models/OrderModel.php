<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\DevMode;
use App\Core\Paginator;
use App\Core\Steam;

/**
 * Store orders (panel_orders): a purchase of a VIP package via PayPal.
 *
 * Statuses: pending (created, not paid) -> paid (captured, VIP granted)
 *           -> refunded (money returned + VIP revoked). Also: cancelled.
 *
 * Mirrors ContestModel's self-heal approach: the table is created on the fly
 * (CREATE TABLE IF NOT EXISTS) so a manual upload works without running the
 * migration, and newer columns are added to older installs at runtime.
 */
final class OrderModel
{
    public const TABLE = 'panel_orders';
    public const ITEMS = 'panel_order_items';

    private static bool $ensured = false;
    private static bool $columnsEnsured = false;
    private static bool $itemsEnsured = false;

    public static function available(): bool
    {
        if (Database::tableExists(self::TABLE)) {
            self::ensureColumns();
            return true;
        }
        self::ensureTables();
        return self::tableExistsFresh();
    }

    private static function tableExistsFresh(): bool
    {
        try {
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?',
                [self::TABLE]
            ) >= 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        if (DevMode::enabled() && (string) Config::get('db.name', '') === '') {
            return;
        }

        $sql = 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(24) NOT NULL,
            `buyer_steamid` VARCHAR(32) NOT NULL,
            `buyer_name` VARCHAR(128) NULL,
            `beneficiary_steamid` VARCHAR(32) NOT NULL,
            `beneficiary_name` VARCHAR(128) NULL,
            `package_id` VARCHAR(64) NOT NULL,
            `package_name` VARCHAR(128) NOT NULL,
            `vip_group` VARCHAR(64) NULL,
            `vip_days` INT NOT NULL DEFAULT 0,
            `admin_group_id` INT NULL,
            `admin_group_name` VARCHAR(64) NULL,
            `admin_immunity` INT NOT NULL DEFAULT 0,
            `admin_days` INT NOT NULL DEFAULT 0,
            `admin_expires_at` DATETIME NULL,
            `admin_granted` TINYINT(1) NOT NULL DEFAULT 0,
            `server_id` VARCHAR(16) NOT NULL DEFAULT \'all\',
            `currency` VARCHAR(8) NOT NULL DEFAULT \'EUR\',
            `list_net_cents` INT NOT NULL DEFAULT 0,
            `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
            `net_cents` INT NOT NULL DEFAULT 0,
            `fee_cents` INT NOT NULL DEFAULT 0,
            `total_cents` INT NOT NULL DEFAULT 0,
            `buyer_email` VARCHAR(190) NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `provider` VARCHAR(16) NOT NULL DEFAULT \'paypal\',
            `provider_order_id` VARCHAR(64) NULL,
            `provider_capture_id` VARCHAR(64) NULL,
            `vip_granted` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `paid_at` DATETIME NULL,
            `refunded_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_code` (`code`),
            KEY `idx_buyer` (`buyer_steamid`),
            KEY `idx_status` (`status`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

        try {
            Database::createTable($sql);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] order ensureTables failed: ' . $e->getMessage());
        }
    }

    public static function ensureColumns(): void
    {
        if (self::$columnsEnsured) {
            return;
        }
        self::$columnsEnsured = true;

        if (DevMode::enabled() && (string) Config::get('db.name', '') === '') {
            return;
        }
        $definitions = [
            'refunded_at' => 'DATETIME NULL',
            'admin_group_id' => 'INT NULL',
            'admin_group_name' => 'VARCHAR(64) NULL',
            'admin_immunity' => 'INT NOT NULL DEFAULT 0',
            'admin_days' => 'INT NOT NULL DEFAULT 0',
            'admin_expires_at' => 'DATETIME NULL',
            'admin_granted' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'list_net_cents' => 'INT NOT NULL DEFAULT 0',
            'discount_percent' => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
            'buyer_email' => 'VARCHAR(190) NULL',
            'discount_code' => 'VARCHAR(48) NULL',
            // Store credits (cs2-store): how many credits this order grants and
            // whether they've been applied yet.
            'credit_amount' => 'INT NOT NULL DEFAULT 0',
            'credits_granted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];
        try {
            $rows = Database::all(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [self::TABLE]
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] order column inspection failed: ' . $e->getMessage());
            return;
        }
        $existing = array_fill_keys(array_column($rows, 'column_name'), true);
        foreach ($definitions as $column => $definition) {
            if (isset($existing[$column])) {
                continue;
            }
            try {
                Database::run("ALTER TABLE `" . self::TABLE . "` ADD COLUMN `{$column}` {$definition}");
            } catch (\Throwable $e) {
                error_log('[CS2Panel] order addColumnIfMissing ' . self::TABLE . '.' . $column . ': ' . $e->getMessage());
            }
        }
    }

    private static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        try {
            $exists = (int) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );
            if ($exists === 0) {
                Database::run("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] order addColumnIfMissing ' . $table . '.' . $column . ': ' . $e->getMessage());
        }
    }

                                                                        
    // CRUD
                                                                        

    /**
     * Create a pending order. Amounts are in cents.
     * @param array<string,mixed> $data
     * @return int new order id (0 on failure)
     */
    public static function create(array $data): int
    {
        if (!self::available()) {
            return 0;
        }
        $adminGroupId = isset($data['admin_group_id']) && $data['admin_group_id'] !== null && $data['admin_group_id'] !== ''
            ? (int) $data['admin_group_id'] : null;

        Database::run(
            'INSERT INTO ' . self::TABLE . '
                (code, buyer_steamid, buyer_name, beneficiary_steamid, beneficiary_name,
                 package_id, package_name, vip_group, vip_days,
                 admin_group_id, admin_group_name, admin_immunity, admin_days,
                 credit_amount,
                 server_id, currency, list_net_cents, discount_percent, net_cents, fee_cents, total_cents,
                 buyer_email, discount_code, status, provider)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) $data['code'],
                (string) $data['buyer_steamid'],
                mb_substr((string) ($data['buyer_name'] ?? ''), 0, 128) ?: null,
                (string) $data['beneficiary_steamid'],
                mb_substr((string) ($data['beneficiary_name'] ?? ''), 0, 128) ?: null,
                mb_substr((string) $data['package_id'], 0, 64),
                mb_substr((string) $data['package_name'], 0, 128),
                mb_substr((string) ($data['vip_group'] ?? ''), 0, 64) ?: null,
                max(0, (int) ($data['vip_days'] ?? 0)),
                $adminGroupId,
                mb_substr((string) ($data['admin_group_name'] ?? ''), 0, 64) ?: null,
                max(0, (int) ($data['admin_immunity'] ?? 0)),
                max(0, (int) ($data['admin_days'] ?? 0)),
                max(0, (int) ($data['credit_amount'] ?? 0)),
                mb_substr((string) ($data['server_id'] ?? 'all'), 0, 16),
                mb_substr((string) ($data['currency'] ?? 'EUR'), 0, 8),
                max(0, (int) ($data['list_net_cents'] ?? ($data['net_cents'] ?? 0))),
                round(max(0.0, min(100.0, (float) ($data['discount_percent'] ?? 0))), 2),
                max(0, (int) ($data['net_cents'] ?? 0)),
                max(0, (int) ($data['fee_cents'] ?? 0)),
                max(0, (int) ($data['total_cents'] ?? 0)),
                mb_substr((string) ($data['buyer_email'] ?? ''), 0, 190) ?: null,
                mb_substr((string) ($data['discount_code'] ?? ''), 0, 48) ?: null,
                'pending',
                mb_substr((string) ($data['provider'] ?? 'paypal'), 0, 16),
            ]
        );
        return (int) Database::lastInsertId();
    }

    public static function find(int $id): ?array
    {
        if (!self::available()) {
            return null;
        }
        return Database::one('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1', [$id]);
    }

    public static function findByCode(string $code): ?array
    {
        if (!self::available()) {
            return null;
        }
        return Database::one('SELECT * FROM ' . self::TABLE . ' WHERE code = ? LIMIT 1', [$code]);
    }

    public static function findByProviderOrder(string $providerOrderId): ?array
    {
        if (!self::available() || $providerOrderId === '') {
            return null;
        }
        return Database::one(
            'SELECT * FROM ' . self::TABLE . ' WHERE provider_order_id = ? LIMIT 1',
            [$providerOrderId]
        );
    }

    /** Attach the PayPal order id right after creating the provider order. */
    public static function setProviderOrder(int $id, string $providerOrderId): void
    {
        if (!self::available()) {
            return;
        }
        Database::run(
            'UPDATE ' . self::TABLE . ' SET provider_order_id = ? WHERE id = ?',
            [mb_substr($providerOrderId, 0, 64), $id]
        );
    }

    /** Mark an order paid (captured). */
    public static function markPaid(int $id, string $captureId): void
    {
        if (!self::available()) {
            return;
        }
        Database::run(
            'UPDATE ' . self::TABLE . '
                SET status = \'paid\', provider_capture_id = ?, paid_at = NOW()
              WHERE id = ?',
            [mb_substr($captureId, 0, 64), $id]
        );
    }

    public static function markVipGranted(int $id): void
    {
        if (!self::available()) {
            return;
        }
        Database::run('UPDATE ' . self::TABLE . ' SET vip_granted = 1 WHERE id = ?', [$id]);
    }

    public static function markCreditsGranted(int $id): void
    {
        if (!self::available()) {
            return;
        }
        Database::run('UPDATE ' . self::TABLE . ' SET credits_granted = 1 WHERE id = ?', [$id]);
    }

    /** Mark one order ITEM's credits as granted (cart/multi-item orders). */
    public static function markItemCreditsGranted(int $itemId): void
    {
        if (!self::itemsAvailable()) {
            return;
        }
        Database::run('UPDATE ' . self::ITEMS . ' SET credits_granted = 1 WHERE id = ?', [$itemId]);
    }

    /**
     * Grant the store credits for one order ITEM (cart line) to its
     * beneficiary. Idempotent via credits_granted. Returns true on success/no-op.
     */
    public static function grantCreditsItem(array $item): bool
    {
        $amount = (int) ($item['credit_amount'] ?? 0);
        if ($amount <= 0) {
            return true;
        }
        if (!empty($item['credits_granted'])) {
            return true;
        }
        $steamId = (string) ($item['beneficiary_steamid'] ?? '');
        if ($steamId === '' || !\App\Models\StoreCreditsModel::tableReady()) {
            return false;
        }
        $name = (string) ($item['beneficiary_name'] ?? 'Player');
        if (\App\Models\StoreCreditsModel::add($steamId, $amount, $name) === null) {
            return false;
        }
        self::markItemCreditsGranted((int) ($item['id'] ?? 0));
        return true;
    }

    /**
     * Grant the store credits for a paid order to its beneficiary. Idempotent:
     * returns true (no-op) when there are no credits to grant or they were
     * already granted, so re-running fulfillment never double-credits.
     */
    public static function grantCredits(array $order): bool
    {
        $amount = (int) ($order['credit_amount'] ?? 0);
        if ($amount <= 0) {
            return true; // not a credits order — nothing to do
        }
        if (!empty($order['credits_granted'])) {
            return true; // already applied
        }
        $steamId = (string) ($order['beneficiary_steamid'] ?? '');
        if ($steamId === '' || !\App\Models\StoreCreditsModel::tableReady()) {
            return false;
        }
        $name = (string) ($order['beneficiary_name'] ?? 'Player');
        $new = \App\Models\StoreCreditsModel::add($steamId, $amount, $name);
        if ($new === null) {
            return false;
        }
        self::markCreditsGranted((int) ($order['id'] ?? 0));
        return true;
    }

    public static function markRefunded(int $id): void
    {
        if (!self::available()) {
            return;
        }
        Database::run(
            'UPDATE ' . self::TABLE . ' SET status = \'refunded\', refunded_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    public static function markCancelled(int $id): void
    {
        if (!self::available()) {
            return;
        }
        Database::run(
            'UPDATE ' . self::TABLE . ' SET status = \'cancelled\' WHERE id = ? AND status = \'pending\'',
            [$id]
        );
    }

    /**
     * Auto-cancel orders left in "pending" for longer than $minutes. A pending
     * order is one that was created but never captured/paid (buyer abandoned
     * PayPal, closed the tab, payment never completed). Leaving them pending
     * clutters the list and can wrongly count as an active purchase, so they're
     * expired to "cancelled" after a grace period.
     *
     * Idempotent and safe to call repeatedly: only rows still in 'pending' and
     * older than the cutoff are touched, and nothing was granted for a pending
     * order so there's nothing to revoke. Called from the cron endpoint (always)
     * and lazily on manager page loads (fallback when no cron is configured).
     *
     * @return int number of orders cancelled
     */
    public static function cancelStalePending(int $minutes = 10): int
    {
        if (!self::available()) {
            return 0;
        }
        $minutes = max(1, $minutes);
        try {
            $stmt = Database::run(
                'UPDATE ' . self::TABLE . "
                    SET status = 'cancelled'
                  WHERE status = 'pending'
                    AND created_at < (NOW() - INTERVAL ? MINUTE)",
                [$minutes]
            );
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] cancelStalePending failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Paginated list of orders. Filters: status, search (code/steamid/name).
     * When $buyerSteamId is set, only that buyer's own orders are returned.
     */
    public static function paginate(array $filters, int $page, int $perPage, ?string $buyerSteamId = null): Paginator
    {
        // No DB / table yet: return an empty page without querying.
        if (!self::available()) {
            $empty = new Paginator();
            $empty->page = max(1, $page);
            $empty->perPage = $perPage;
            return $empty;
        }
        $where = [];
        $args  = [];

        if ($buyerSteamId !== null) {
            $where[] = 'buyer_steamid = ?';
            $args[]  = $buyerSteamId;
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'status = ?';
            $args[]  = $status;
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $steamId = Steam::toSteamId64Any($search);
            $where[] = '(code LIKE ? OR buyer_steamid LIKE ? OR beneficiary_steamid LIKE ? OR buyer_name LIKE ? OR package_name LIKE ?'
                . ($steamId !== null ? ' OR buyer_steamid = ? OR beneficiary_steamid = ?)' : ')');
            $like = '%' . $search . '%';
            array_push($args, $like, $like, $like, $like, $like);
            if ($steamId !== null) array_push($args, $steamId, $steamId);
        }

        $clause = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        return Paginator::make(
            'SELECT * FROM ' . self::TABLE . $clause,
            'SELECT COUNT(*) FROM ' . self::TABLE . $clause,
            $args,
            $page,
            $perPage,
            'id DESC'
        );
    }

    /**
     * Grant the order's VIP to the beneficiary. Mirrors the contest award loop:
     * grant on every VIPCore server (skip where the player already has VIP on
     * that server), unless the package targets a single server_id. Idempotent
     * per server via VipModel::exists(). Returns true if at least one grant was
     * made (or the beneficiary already had VIP everywhere — treated as done).
     */
    public static function grantVip(array $order): bool
    {
        $steamId = (string) ($order['beneficiary_steamid'] ?? '');
        $group = (string) ($order['vip_group'] ?? '');
        // Admin-only package (no VIP part) — nothing to do here.
        if ($group === '') {
            return false;
        }
        if ($steamId === '' || !\App\Models\VipModel::enabled()) {
            return false;
        }
        $days  = (int) $order['vip_days'];
        $name  = (string) ($order['beneficiary_name'] ?? 'VIP');
        $target = (string) ($order['server_id'] ?? 'all');

        $servers = \App\Models\VipModel::servers();
        if (!$servers) {
            return false;
        }
        $did = false;
        $already = true;
        foreach ($servers as $srv) {
            $sid = (int) $srv['serverId'];
            // If the package targets one server, skip the others.
            if ($target !== 'all' && (string) $sid !== $target) {
                continue;
            }
            try {
                if (!\App\Models\VipModel::exists($steamId, $sid)) {
                    \App\Models\VipModel::create($steamId, $name !== '' ? $name : 'VIP', $group, $days, $sid);
                    $did = true;
                    $already = false;
                }
            } catch (\Throwable $e) {
                error_log('[CS2Panel] store VIP grant failed for ' . $steamId . ' sid ' . $sid . ': ' . $e->getMessage());
                $already = false;
            }
        }
        if ($did) {
            \App\Core\ServerSync::refreshVip();
        }
        return $did || $already;
    }

    /**
     * Revoke the VIP that was granted for an order (used on refund). Removes the
     * beneficiary's VIP on the order's target server(s). Best-effort.
     */
    public static function revokeVip(array $order): void
    {
        $steamId = (string) ($order['beneficiary_steamid'] ?? '');
        if ($steamId === '' || !\App\Models\VipModel::enabled()) {
            return;
        }
        $target = (string) ($order['server_id'] ?? 'all');
        foreach (\App\Models\VipModel::servers() as $srv) {
            $sid = (int) $srv['serverId'];
            if ($target !== 'all' && (string) $sid !== $target) {
                continue;
            }
            try {
                \App\Models\VipModel::remove($steamId, $sid);
            } catch (\Throwable $e) {
                error_log('[CS2Panel] store VIP revoke failed for ' . $steamId . ' sid ' . $sid . ': ' . $e->getMessage());
            }
        }
        \App\Core\ServerSync::refreshVip();
    }

                                                                        
    // Admin perk (mixed packages)
                                                                        

    /**
     * Grant the order's admin group to the beneficiary by creating a game admin
     * (sa_admins) with that group + immunity. If the player is already an admin,
     * their group/immunity is updated. Expiry (if any) is tracked in this table,
     * not in the plugin. Returns true if a grant/update was applied.
     */
    public static function grantAdmin(array $order): bool
    {
        $groupId = (int) ($order['admin_group_id'] ?? 0);
        $steamId = (string) ($order['beneficiary_steamid'] ?? '');
        if ($groupId <= 0 || $steamId === '') {
            return false; // no admin part
        }
        $p = Config::get('sa_prefix', 'sa_');
        $name = (string) ($order['beneficiary_name'] ?? 'Admin');
        $immunity = max(0, (int) ($order['admin_immunity'] ?? 0));

        try {
            $existing = Database::one(
                "SELECT id FROM {$p}admins WHERE player_steamid = ? LIMIT 1",
                [$steamId]
            );
            if ($existing) {
                Database::run(
                    "UPDATE {$p}admins SET group_id = ?, immunity = ? WHERE id = ?",
                    [$groupId, $immunity, (int) $existing['id']]
                );
            } else {
                Database::run(
                    "INSERT INTO {$p}admins (player_name, player_steamid, flags, immunity, group_id, created)
                     VALUES (?, ?, '', ?, ?, NOW())",
                    [$name !== '' ? $name : 'Admin', $steamId, $immunity, $groupId]
                );
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] store grantAdmin failed for ' . $steamId . ': ' . $e->getMessage());
            return false;
        }

        \App\Core\ServerSync::refreshAdmins();
        return true;
    }

    /** The admin expiry timestamp for an order/item (null = permanent). */
    public static function adminExpiryFor(array $row): ?string
    {
        $days = max(0, (int) ($row['admin_days'] ?? 0));
        return $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
    }

    /**
     * Revoke the admin grant made for an order: delete the beneficiary's
     * sa_admins row (and its direct flags). Best-effort.
     */
    public static function revokeAdmin(array $order): void
    {
        $groupId = (int) ($order['admin_group_id'] ?? 0);
        $steamId = (string) ($order['beneficiary_steamid'] ?? '');
        if ($groupId <= 0 || $steamId === '') {
            return;
        }
        $p = Config::get('sa_prefix', 'sa_');
        try {
            $row = Database::one("SELECT id FROM {$p}admins WHERE player_steamid = ? LIMIT 1", [$steamId]);
            if ($row) {
                $aid = (int) $row['id'];
                Database::run("DELETE FROM {$p}admins_flags WHERE admin_id = ?", [$aid]);
                Database::run("DELETE FROM {$p}admins WHERE id = ?", [$aid]);
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] store revokeAdmin failed for ' . $steamId . ': ' . $e->getMessage());
            return;
        }
        \App\Core\ServerSync::refreshAdmins();
    }

    /**
     * Revoke admin grants whose expiry has passed. Called from the store/contest
     * cron and lazily. Returns the number of grants revoked.
     */
    public static function purgeExpiredAdmins(): int
    {
        $n = 0;
        // Legacy single-item orders (admin perk stored on panel_orders).
        if (self::available()) {
            $rows = Database::all(
                'SELECT * FROM ' . self::TABLE . "
                  WHERE admin_granted = 1
                    AND admin_expires_at IS NOT NULL
                    AND admin_expires_at <= NOW()"
            );
            foreach ($rows as $o) {
                self::revokeAdmin($o);
                Database::run('UPDATE ' . self::TABLE . ' SET admin_granted = 0 WHERE id = ?', [(int) $o['id']]);
                $n++;
            }
        }
        // Cart items (admin perk stored per item).
        if (self::itemsAvailable()) {
            $rows = Database::all(
                'SELECT * FROM ' . self::ITEMS . "
                  WHERE admin_granted = 1
                    AND admin_expires_at IS NOT NULL
                    AND admin_expires_at <= NOW()"
            );
            foreach ($rows as $it) {
                self::revokeAdmin($it);
                Database::run('UPDATE ' . self::ITEMS . ' SET admin_granted = 0 WHERE id = ?', [(int) $it['id']]);
                $n++;
            }
        }
        return $n;
    }

                                                                        
    // Order items (cart)
                                                                        

    public static function itemsAvailable(): bool
    {
        if (Database::tableExists(self::ITEMS)) {
            return true;
        }
        self::ensureItemsTable();
        try {
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?',
                [self::ITEMS]
            ) >= 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function ensureItemsTable(): void
    {
        if (self::$itemsEnsured) {
            return;
        }
        self::$itemsEnsured = true;
        if (DevMode::enabled() && (string) Config::get('db.name', '') === '') {
            return;
        }
        $sql = 'CREATE TABLE IF NOT EXISTS `' . self::ITEMS . '` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `order_id` INT NOT NULL,
            `order_code` VARCHAR(32) NULL,
            `beneficiary_steamid` VARCHAR(32) NOT NULL,
            `beneficiary_name` VARCHAR(128) NULL,
            `package_id` VARCHAR(64) NOT NULL,
            `package_name` VARCHAR(128) NOT NULL,
            `vip_group` VARCHAR(64) NULL,
            `vip_days` INT NOT NULL DEFAULT 0,
            `admin_group_id` INT NULL,
            `admin_group_name` VARCHAR(64) NULL,
            `admin_immunity` INT NOT NULL DEFAULT 0,
            `admin_days` INT NOT NULL DEFAULT 0,
            `admin_expires_at` DATETIME NULL,
            `admin_granted` TINYINT(1) NOT NULL DEFAULT 0,
            `vip_granted` TINYINT(1) NOT NULL DEFAULT 0,
            `credit_amount` INT NOT NULL DEFAULT 0,
            `credits_granted` TINYINT(1) NOT NULL DEFAULT 0,
            `server_id` VARCHAR(16) NOT NULL DEFAULT \'all\',
            `list_net_cents` INT NOT NULL DEFAULT 0,
            `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
            `net_cents` INT NOT NULL DEFAULT 0,
            `fee_cents` INT NOT NULL DEFAULT 0,
            `total_cents` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order` (`order_id`),
            KEY `idx_admin_exp` (`admin_granted`, `admin_expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        try {
            Database::createTable($sql);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] order items ensureTable failed: ' . $e->getMessage());
        }
        // Self-heal: older installs created the items table before order_code
        // existed. Add it so each item row also carries its parent order code.
        self::addColumnIfMissing(self::ITEMS, 'order_code', 'VARCHAR(32) NULL');
        // Store credits (cs2-store) per-item columns, for installs that predate them.
        self::addColumnIfMissing(self::ITEMS, 'credit_amount', 'INT NOT NULL DEFAULT 0');
        self::addColumnIfMissing(self::ITEMS, 'credits_granted', 'TINYINT(1) NOT NULL DEFAULT 0');
    }

    /**
     * Add an item to an order. Amounts in cents.
     * @param array<string,mixed> $it
     */
    public static function addItem(int $orderId, array $it): int
    {
        if (!self::itemsAvailable()) {
            return 0;
        }
        $agid = isset($it['admin_group_id']) && (int) $it['admin_group_id'] > 0 ? (int) $it['admin_group_id'] : null;
        Database::run(
            'INSERT INTO ' . self::ITEMS . '
                (order_id, order_code, beneficiary_steamid, beneficiary_name, package_id, package_name,
                 vip_group, vip_days, admin_group_id, admin_group_name, admin_immunity, admin_days,
                 credit_amount, server_id, list_net_cents, discount_percent, net_cents, fee_cents, total_cents)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId,
                mb_substr((string) ($it['order_code'] ?? ''), 0, 32) ?: null,
                (string) $it['beneficiary_steamid'],
                mb_substr((string) ($it['beneficiary_name'] ?? ''), 0, 128) ?: null,
                mb_substr((string) $it['package_id'], 0, 64),
                mb_substr((string) $it['package_name'], 0, 128),
                mb_substr((string) ($it['vip_group'] ?? ''), 0, 64) ?: null,
                max(0, (int) ($it['vip_days'] ?? 0)),
                $agid,
                mb_substr((string) ($it['admin_group_name'] ?? ''), 0, 64) ?: null,
                max(0, (int) ($it['admin_immunity'] ?? 0)),
                max(0, (int) ($it['admin_days'] ?? 0)),
                max(0, (int) ($it['credit_amount'] ?? 0)),
                mb_substr((string) ($it['server_id'] ?? 'all'), 0, 16),
                max(0, (int) ($it['list_net_cents'] ?? 0)),
                round(max(0.0, min(100.0, (float) ($it['discount_percent'] ?? 0))), 2),
                max(0, (int) ($it['net_cents'] ?? 0)),
                max(0, (int) ($it['fee_cents'] ?? 0)),
                max(0, (int) ($it['total_cents'] ?? 0)),
            ]
        );
        return (int) Database::lastInsertId();
    }

    /** @return array<int,array> the items of an order */
    public static function items(int $orderId): array
    {
        if (!self::itemsAvailable()) {
            return [];
        }
        return Database::all('SELECT * FROM ' . self::ITEMS . ' WHERE order_id = ? ORDER BY id ASC', [$orderId]);
    }

    /**
     * Fetch items for MANY orders in a single query, grouped by order_id.
     * Avoids the N+1 of calling items() per order on the Orders list page.
     * @param int[] $orderIds
     * @return array<int, array<int,array>> orderId => items[]
     */
    public static function itemsForOrders(array $orderIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$ids || !self::itemsAvailable()) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            'SELECT * FROM ' . self::ITEMS . ' WHERE order_id IN (' . $ph . ') ORDER BY order_id ASC, id ASC',
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['order_id']][] = $r;
        }
        return $out;
    }

    public static function markItemVipGranted(int $itemId): void
    {
        if (self::itemsAvailable()) {
            Database::run('UPDATE ' . self::ITEMS . ' SET vip_granted = 1 WHERE id = ?', [$itemId]);
        }
    }

    public static function markItemAdminGranted(int $itemId, ?string $expiresAt): void
    {
        if (self::itemsAvailable()) {
            Database::run(
                'UPDATE ' . self::ITEMS . ' SET admin_granted = 1, admin_expires_at = ? WHERE id = ?',
                [$expiresAt, $itemId]
            );
        }
    }

    /** Total net revenue (paid, not refunded) in cents. */
    public static function paidNetTotalCents(): int
    {
        if (!self::available()) {
            return 0;
        }
        return (int) Database::scalar(
            'SELECT COALESCE(SUM(net_cents), 0) FROM ' . self::TABLE . ' WHERE status = \'paid\''
        );
    }
}

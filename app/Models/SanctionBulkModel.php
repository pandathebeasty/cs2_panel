<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Lang;
use App\Core\Steam;

final class SanctionBulkModel
{
    public const MAX_SELECTION = 200;
    public const MAX_DURATION = 2147483647;
    public const ACTIONS = [
        'bans' => ['edit', 'ban', 'unban'],
        'mutes' => ['edit', 'mute', 'gag', 'silence', 'unmute', 'ungag', 'unsilence', 'lift'],
    ];

    public static function actionMode(string $action): string
    {
        return match ($action) {
            'edit' => 'edit',
            'ban', 'mute', 'gag', 'silence' => 'create',
            'unban', 'unmute', 'ungag', 'unsilence', 'lift' => 'lift',
            default => throw new \InvalidArgumentException(Lang::t('sanctions.bulk_invalid')),
        };
    }

    /**
     * @return array<int,array{before:array,after:array}>
     */
    public static function update(string $kind, array $input, ?string $actorSteamId, string $actorName = 'Web'): array
    {
        $action = $input['bulk_action'] ?? 'edit';
        if (!isset(self::ACTIONS[$kind]) || !is_string($action) || !in_array($action, self::ACTIONS[$kind], true)) {
            throw new \InvalidArgumentException(Lang::t('sanctions.bulk_invalid'));
        }
        $mode = self::actionMode($action);
        $ids = $input['ids'] ?? null;
        if (!is_array($ids) || !$ids || count($ids) > self::MAX_SELECTION) {
            throw new \InvalidArgumentException(Lang::t('sanctions.bulk_selection_invalid', ['max' => self::MAX_SELECTION]));
        }
        $selected = [];
        foreach ($ids as $value) {
            $id = is_string($value) || is_int($value)
                ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($id === false) {
                throw new \InvalidArgumentException(Lang::t('sanctions.bulk_selection_invalid', ['max' => self::MAX_SELECTION]));
            }
            $selected[$id] = $id;
        }
        $ids = array_values($selected);
        sort($ids, SORT_NUMERIC);
        if (($input['confirm_bulk'] ?? null) !== '1') {
            throw new \InvalidArgumentException(Lang::t('sanctions.bulk_confirmation_required'));
        }

        $apply = $input['apply'] ?? [];
        $fields = $mode === 'lift' ? ['reason'] : ['reason', 'duration', 'server_id'];
        if (!is_array($apply) || ($mode === 'edit' && !$apply) || array_diff(array_keys($apply), $fields)) {
            throw new \InvalidArgumentException(Lang::t('sanctions.bulk_fields_required'));
        }
        $changes = [];
        foreach ($apply as $field => $enabled) {
            if ($enabled !== '1' || !array_key_exists($field, $input)) {
                throw new \InvalidArgumentException(Lang::t('sanctions.bulk_invalid'));
            }
            $value = $input[$field] ?? null;
            if ($field === 'reason') {
                if (!is_string($value) || preg_match('//u', $value) !== 1
                    || trim($value) === '' || mb_strlen(trim($value), 'UTF-8') > 255) {
                    throw new \InvalidArgumentException(Lang::t('sanctions.bulk_reason_invalid'));
                }
                $changes[$field] = trim($value);
            } elseif ($field === 'duration') {
                $duration = is_string($value) || is_int($value)
                    ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_DURATION]])
                    : false;
                if ($duration === false) {
                    throw new \InvalidArgumentException(Lang::t('sanctions.bulk_duration_invalid'));
                }
                $changes[$field] = $duration;
            } else {
                $changes[$field] = $value;
            }
        }

        $prefix = (string) Config::get('sa_prefix', 'sa_');
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) {
            throw new \RuntimeException('The SimpleAdmin table prefix is invalid.');
        }
        $table = $prefix . $kind;
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if (array_key_exists('server_id', $changes)) {
                $changes['server_id'] = ServerModel::validatedSelection($changes['server_id']);
            }
            $records = Database::all(
                "SELECT * FROM `{$table}` WHERE `id` IN ({$placeholders}) ORDER BY `id` FOR UPDATE",
                $ids
            );
            if (count($records) !== count($ids)) {
                throw new \InvalidArgumentException(Lang::t('sanctions.bulk_missing'));
            }
            if ($mode === 'create' && !array_key_exists('server_id', $changes)) {
                foreach (array_unique(array_column($records, 'server_id'), SORT_REGULAR) as $serverId) {
                    if ($serverId !== null && $serverId !== '' && (int) $serverId !== 0) {
                        ServerModel::validatedSelection($serverId);
                    }
                }
            }
            $updated = [];
            $adminId = null;
            $now = time();
            foreach ($records as $record) {
                $id = (int) $record['id'];
                if ($mode === 'create') {
                    $data = self::newRecordData($kind, $action, $record, $changes, $actorSteamId, $actorName);
                    if (self::hasActiveTarget($kind, $table, $data, $now)) continue;
                    $newId = $kind === 'bans' ? BanModel::create($data) : MuteModel::create($data);
                    $updated[$newId] = ['before' => $record, 'after' => []];
                    continue;
                }
                if ($mode === 'lift') {
                    $type = match ($action) {
                        'unmute' => 'MUTE',
                        'ungag' => 'GAG',
                        'unsilence' => 'SILENCE',
                        default => null,
                    };
                    if (!self::isActive($record, $now) || ($type !== null && $record['type'] !== $type)) continue;
                    $adminId ??= ServerModel::adminIdBySteamId($actorSteamId);
                    $reason = $changes['reason'] ?? Lang::t('sanctions.bulk_lift_reason');
                    if ($kind === 'bans') BanModel::unban($id, $adminId, $reason);
                    else MuteModel::unmute($id, $adminId, $reason);
                    $updated[$id] = ['before' => $record, 'after' => []];
                    continue;
                }
                $patch = [];
                foreach (['reason', 'duration', 'server_id'] as $field) {
                    if (!array_key_exists($field, $changes)) continue;
                    $old = $field === 'duration' ? (int) $record[$field]
                        : ($field === 'server_id' ? ($record[$field] === null ? null : (int) $record[$field]) : (string) $record[$field]);
                    if ($old !== $changes[$field]) $patch[$field] = $changes[$field];
                }
                if (array_key_exists('duration', $changes) && !in_array($record['status'], ['UNBANNED', 'UNMUTED'], true)) {
                    $ends = null;
                    if ($changes['duration'] > 0) {
                        $created = strtotime((string) ($record['created'] ?? ''));
                        if ($created === false || empty($record['created'])) {
                            throw new \RuntimeException('Cannot recompute expiry for ' . $kind . ' #' . (int) $record['id'] . ': original creation time is missing or invalid.');
                        }
                        $timestamp = $created + $changes['duration'] * 60;
                        if ($timestamp > 253402300799) {
                            throw new \InvalidArgumentException(Lang::t('sanctions.bulk_duration_invalid'));
                        }
                        $ends = date('Y-m-d H:i:s', $timestamp);
                    }
                    if (($record['ends'] ?? null) !== $ends) $patch['ends'] = $ends;
                }
                if (!$patch) continue;
                $set = implode(', ', array_map(static fn(string $field): string => "`{$field}` = ?", array_keys($patch)));
                Database::run("UPDATE `{$table}` SET {$set} WHERE `id` = ?", [...array_values($patch), $id]);
                $updated[$id] = ['before' => $record, 'after' => []];
            }
            if ($updated) {
                $changedIds = array_keys($updated);
                $changedPlaceholders = implode(', ', array_fill(0, count($changedIds), '?'));
                $after = Database::all(
                    "SELECT s.*, servers.hostname AS server_name
                     FROM `{$table}` s
                     LEFT JOIN `{$prefix}servers` servers ON servers.id = s.server_id
                     WHERE s.id IN ({$changedPlaceholders}) ORDER BY s.id",
                    $changedIds
                );
                if (count($after) !== count($updated)) {
                    throw new \RuntimeException('Cannot read the updated sanction snapshots.');
                }
                foreach ($after as $record) $updated[(int) $record['id']]['after'] = $record;
            }
            $pdo->commit();
            return array_values($updated);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    private static function isActive(array $record, int $now): bool
    {
        if ($record['status'] !== 'ACTIVE') return false;
        if ((int) $record['duration'] === 0 || empty($record['ends'])) return true;
        $ends = strtotime((string) $record['ends']);
        if ($ends === false) throw new \RuntimeException('A selected restriction has an invalid expiration time.');
        return $ends > $now;
    }

    private static function newRecordData(string $kind, string $action, array $record, array $changes, ?string $actorSteamId, string $actorName): array
    {
        $steamId = Steam::toSteamId64Any((string) ($record['player_steamid'] ?? ''));
        $ip = trim((string) ($record['player_ip'] ?? ''));
        if ($steamId === null && ($kind !== 'bans' || filter_var($ip, FILTER_VALIDATE_IP) === false)) {
            throw new \InvalidArgumentException(Lang::t('sanctions.bulk_target_invalid', ['id' => (int) $record['id']]));
        }
        $duration = $changes['duration'] ?? (int) $record['duration'];
        if ($duration < 0 || $duration > self::MAX_DURATION || time() + $duration * 60 > 253402300799) {
            throw new \InvalidArgumentException(Lang::t('sanctions.bulk_duration_invalid'));
        }
        $serverId = array_key_exists('server_id', $changes) ? $changes['server_id'] : ($record['server_id'] ?? null);
        return [
            'player_name' => $record['player_name'],
            'player_steamid' => $steamId,
            'player_ip' => $ip !== '' ? $ip : null,
            'admin_steamid' => $actorSteamId ?? '0',
            'admin_name' => $actorName,
            'reason' => $changes['reason'] ?? $record['reason'],
            'duration' => $duration,
            'server_id' => $serverId !== null && (int) $serverId !== 0 ? (int) $serverId : null,
            'type' => strtoupper($action),
        ];
    }

    private static function hasActiveTarget(string $kind, string $table, array $data, int $now): bool
    {
        $identity = $data['player_steamid'] !== null ? '`player_steamid` = ?' : '`player_ip` = ?';
        $params = [$data['player_steamid'] ?? $data['player_ip'], date('Y-m-d H:i:s', $now)];
        $scope = '(`server_id` IS NULL OR `server_id` = 0)';
        if ($data['server_id'] !== null) {
            $scope = '(`server_id` = ? OR `server_id` IS NULL OR `server_id` = 0)';
            $params[] = $data['server_id'];
        }
        $type = '';
        if ($kind === 'mutes') {
            $types = $data['type'] === 'SILENCE' ? ['SILENCE'] : [$data['type'], 'SILENCE'];
            $type = ' AND `type` IN (' . implode(', ', array_fill(0, count($types), '?')) . ')';
            array_push($params, ...$types);
        }
        return Database::one(
            "SELECT `id` FROM `{$table}` WHERE {$identity} AND `status` = 'ACTIVE'
             AND (`duration` = 0 OR `ends` IS NULL OR `ends` > ?) AND {$scope}{$type}
             LIMIT 1 FOR UPDATE",
            $params
        ) !== null;
    }
}

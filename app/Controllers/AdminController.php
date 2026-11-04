<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Steam;

final class AdminController extends Controller
{
    private function p(): string
    {
        return Config::get('sa_prefix', 'sa_');
    }

    public function index(): void
    {
        Auth::requireManage();
        $p = $this->p();

        // Paginate the admin list (like bans/mutes/warns) instead of loading the
        // whole sa_admins table on every page load. Optional name/SteamID search.
        $search = trim((string) $this->query('search', ''));
        $where  = ['a.player_steamid <> 0'];
        $params = [];
        if ($search !== '') {
            $steamId = Steam::toSteamId64Any($search);
            $where[] = '(a.player_name LIKE ? OR a.player_steamid LIKE ?'
                . ($steamId !== null ? ' OR a.player_steamid = ?)' : ')');
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            if ($steamId !== null) $params[] = $steamId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // One row per player: SimpleAdmin stores a separate sa_admins row per
        // server / legacy flag entry, which made the same admin appear several
        // times. Collapse them and aggregate the groups, ids and servers.
        $base = "SELECT MIN(a.id) AS id,
                        a.player_steamid,
                        MAX(a.player_name) AS player_name,
                        MAX(a.immunity) AS immunity,
                        MAX(a.group_id) AS group_id,
                        GROUP_CONCAT(DISTINCT a.group_id) AS group_ids,
                        GROUP_CONCAT(DISTINCT a.id) AS admin_ids,
                        GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ') AS group_name,
                        GROUP_CONCAT(DISTINCT a.server_id) AS server_ids,
                        GROUP_CONCAT(DISTINCT a.flags) AS legacy_flags
                 FROM {$p}admins a
                 LEFT JOIN {$p}groups g ON g.id = a.group_id
                 {$whereSql}
                 GROUP BY a.player_steamid";
        $count = "SELECT COUNT(DISTINCT a.player_steamid) FROM {$p}admins a {$whereSql}";

        $pager  = \App\Core\Paginator::make(
            $base,
            $count,
            $params,
            $this->page(),
            (int) Config::get('app.per_page', 25),
            'MAX(a.immunity) DESC, MAX(a.player_name) ASC'
        );
        $admins = $pager->items;

                                                                                 
        $adminGroupIds = [];
        foreach ($admins as $admin) {
            foreach (array_filter(array_map('intval', explode(',', (string) ($admin['group_ids'] ?? '')))) as $groupId) {
                $adminGroupIds[$groupId] = true;
            }
        }
        $inheritedFlagsByAdmin = [];
        if ($adminGroupIds) {
            $groupIds = array_keys($adminGroupIds);
            $ph = implode(',', array_fill(0, count($groupIds), '?'));
            $flagsByGroup = [];
            foreach (Database::all("SELECT group_id, flag FROM {$p}groups_flags WHERE group_id IN ({$ph})", $groupIds) as $row) {
                $flagsByGroup[(int) $row['group_id']][] = (string) $row['flag'];
            }
            foreach ($admins as $admin) {
                $effective = [];
                foreach (array_filter(array_map('intval', explode(',', (string) ($admin['group_ids'] ?? '')))) as $groupId) {
                    foreach ($flagsByGroup[$groupId] ?? [] as $flag) {
                        if (\App\Core\Flags::isValid($flag)) {
                            $effective[$flag] = true;
                        }
                    }
                }
                $inheritedFlagsByAdmin[(int) $admin['id']] = array_keys($effective);
            }
        }

        $groups = \App\Models\GroupModel::options();
        // Flags inherited from each group, so the edit modal can show them.
        $groupFlags = [];
        foreach ($groups as $g) {
            $groupFlags[(int) $g['id']] = array_values(array_filter(
                \App\Models\GroupModel::flags((int) $g['id']),
                [\App\Core\Flags::class, 'isValid']
            ));
        }
        $servers = \App\Models\ServerModel::options();

        // Steam avatars for the listed admins (batch; needs a Steam Web API key).
        $steamIds = [];
        foreach ($admins as $a) {
            $sid = (string) ($a['player_steamid'] ?? '');
            if (\App\Core\Steam::isValidSteamId64($sid)) {
                $steamIds[] = $sid;
            }
        }
        $avatars = $steamIds ? \App\Core\Steam::avatars($steamIds) : [];

        $this->view('admins/index', [
            'admins' => $admins,
            'inheritedFlagsByAdmin' => $inheritedFlagsByAdmin,
            'pager'  => $pager,
            'search' => $search,
            'groups' => $groups,
            'groupFlags' => $groupFlags,
            'servers' => $servers,
            'avatars' => $avatars,
            'flagList' => $this->flagList(),
            'flagGroups' => \App\Core\Flags::grouped(),
            'customFlags' => \App\Core\Flags::custom(),
            'csrf'   => $this->csrfToken(),
        ]);
    }

    public function store(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $p = $this->p();

        $steamid = $this->steamInput('player_steamid');
        $name    = trim((string) $this->input('player_name', ''));
        $immunityRaw = trim((string) $this->input('immunity', ''));
        $groupId = $this->input('group_id') ?: null;
        $serverId = $this->input('server_id');
        $flags   = $this->validFlags((array) $this->input('flags', []));

        // Every field is mandatory — no silent defaults like the name "Admin".
        if ($steamid === '' || $name === '' || $immunityRaw === '' || $groupId === null) {
            $this->flash('error', 'All fields are required: name, SteamID64, immunity and group.');
            $this->redirect('/admins');
        }
        $immunity = (int) $immunityRaw;
        $groupFlag = self::groupReference((int) $groupId);
        if ($groupFlag === '') {
            $this->flash('error', 'The selected group could not be found.');
            $this->redirect('/admins');
        }
        $flags = array_values(array_unique(array_merge($flags, [$groupFlag])));

        // Don't overwrite / duplicate an existing admin — error out instead.
        $exists = Database::scalar(
            "SELECT COUNT(*) FROM {$p}admins WHERE player_steamid = ?",
            [$steamid]
        );
        if ((int) $exists > 0) {
            $this->flash('error', 'An admin with this SteamID64 already exists. Edit the existing entry instead.');
            $this->redirect('/admins');
        }

        $pdo = Database::connection('simpleadmin');
        $pdo->beginTransaction();
        try {
            Database::run(
                "INSERT INTO {$p}admins (player_name, player_steamid, flags, immunity, group_id, server_id, created)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$name, $steamid, implode(',', $flags), $immunity, (int) $groupId, self::serverIdValue($serverId)]
            );
            $adminId = (int) Database::lastInsertId();
            $this->syncFlags($adminId, $flags);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[CS2Panel] admin create failed: ' . $e->getMessage());
            $this->flash('error', 'Admin and flag rows could not be saved.');
            $this->redirect('/admins');
        }
        \App\Core\Flags::remember($flags);
        \App\Core\ServerSync::refreshAdmins();

        \App\Core\Discord::notify(
            'discord.admin_title',
            'discord.act_admin_added',
            'admin',
            [
                'discord.record_id' => '#' . $adminId,
                'discord.group' => $groupFlag,
                'groups.immunity' => (string) $immunity,
                'groups.flags' => implode(', ', $flags),
                'discord.server_id' => $serverId !== '' && $serverId !== null ? (string) self::serverIdValue($serverId) : \App\Core\Lang::t('discord.unavailable'),
            ],
            ['name' => $name, 'steamid' => $steamid]
        );
        \App\Core\Logger::log('admin.create', $steamid, $name);
        $this->flash('success', 'Admin added.');
        $this->redirect('/admins');
    }

    public function update(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $p = $this->p();

        $name     = trim((string) $this->input('player_name', ''));
        $immunityRaw = trim((string) $this->input('immunity', ''));
        $groupId  = $this->input('group_id') ?: null;
        $serverId = $this->input('server_id');
        $cleanFlags = array_values(array_filter(
            $this->validFlags((array) $this->input('flags', [])),
            static fn($flag) => !str_starts_with($flag, '#') && !\App\Core\Flags::isPanelFlag($flag)
        ));

        if ($name === '' || $immunityRaw === '' || $groupId === null) {
            $this->flash('error', 'All fields are required: name, immunity and group.');
            $this->redirect('/admins');
        }
        $immunity = (int) $immunityRaw;
        $groupFlag = self::groupReference((int) $groupId);
        if ($groupFlag === '') {
            $this->flash('error', 'The selected group could not be found.');
            $this->redirect('/admins');
        }
        $storedFlags = array_values(array_unique(array_merge($cleanFlags, [$groupFlag])));

        $pdo = Database::connection('simpleadmin');
        $pdo->beginTransaction();
        try {
            Database::run(
                "UPDATE {$p}admins SET player_name = ?, immunity = ?, group_id = ?, server_id = ?, flags = ? WHERE id = ?",
                [$name, $immunity, (int) $groupId, self::serverIdValue($serverId), implode(',', $storedFlags), (int) $id]
            );
            $this->syncFlags((int) $id, $storedFlags);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[CS2Panel] admin update failed: ' . $e->getMessage());
            $this->flash('error', 'Admin and flag rows could not be updated.');
            $this->redirect('/admins');
        }
        \App\Core\Flags::remember($cleanFlags);
        \App\Core\ServerSync::refreshAdmins();

        $row = Database::one(
            "SELECT a.player_steamid, g.name AS group_name FROM {$p}admins a
             LEFT JOIN {$p}groups g ON g.id = a.group_id WHERE a.id = ?",
            [(int) $id]
        );
        $fields = [
            'common.name'      => $name,
            'discord.group'    => $row['group_name'] ?? \App\Core\Lang::t('admins.no_group'),
            'groups.immunity'  => (string) $immunity,
            'groups.flags'     => $cleanFlags ? implode(', ', $cleanFlags) : \App\Core\Lang::t('common.none'),
        ];
        \App\Core\Discord::notify(
            'discord.admin_title',
            'discord.act_admin_updated',
            'admin',
            $fields,
            ['name' => $name, 'steamid' => $row['player_steamid'] ?? '']
        );
        \App\Core\Logger::log(
            'admin.update',
            $row['player_steamid'] ?? $id,
            $name . ' | ' . \App\Core\Lang::t('common.group') . ': ' . ($row['group_name'] ?? '-')
                . ' | ' . \App\Core\Lang::t('groups.immunity') . ': ' . $immunity
                . ' | ' . \App\Core\Lang::t('groups.flags') . ': ' . ($cleanFlags ? implode(', ', $cleanFlags) : \App\Core\Lang::t('common.none'))
        );
        $this->flash('success', 'Admin updated.');
        $this->redirect('/admins');
    }

    public function delete(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $p = $this->p();
        // Preserve the removed admin's group, immunity and flags for the notice.
                                                          
        $adminRow = Database::one(
            "SELECT a.player_name, a.player_steamid, a.immunity, g.name AS group_name
             FROM {$p}admins a
             LEFT JOIN {$p}groups g ON g.id = a.group_id
             WHERE a.id = ?",
            [(int) $id]
        );
        // One SteamID may have a separate sa_admins row per server.
        $adminIds = [];
        if (!empty($adminRow['player_steamid'])) {
            $adminIds = array_map('intval', array_column(Database::all(
                "SELECT id FROM {$p}admins WHERE player_steamid = ? AND player_steamid <> 0",
                [$adminRow['player_steamid']]
            ), 'id'));
        }
        if (!$adminIds) {
            $adminIds = [(int) $id];
        }
        $idPlaceholders = implode(',', array_fill(0, count($adminIds), '?'));
        $flagRows = Database::all(
            "SELECT flag FROM {$p}admins_flags WHERE admin_id IN ({$idPlaceholders})",
            $adminIds
        );
        $flags = array_column($flagRows, 'flag');

        $pdo = Database::connection('simpleadmin');
        $pdo->beginTransaction();
        try {
            Database::run("DELETE FROM {$p}admins_flags WHERE admin_id IN ({$idPlaceholders})", $adminIds);
            if (!empty($adminRow['player_steamid'])) {
                Database::run(
                    "DELETE FROM {$p}admins WHERE player_steamid = ? AND player_steamid <> 0",
                    [$adminRow['player_steamid']]
                );
            } else {
                Database::run("DELETE FROM {$p}admins WHERE id = ? AND player_steamid <> 0", [(int) $id]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[CS2Panel] admin delete failed: ' . $e->getMessage());
            $this->flash('error', 'Admin and flag rows could not be deleted.');
            $this->redirect('/admins');
        }
        \App\Core\ServerSync::refreshAdmins();

        $fields = [
            'discord.group'    => $adminRow['group_name'] ?? \App\Core\Lang::t('admins.no_group'),
            'groups.immunity'  => (string) (int) ($adminRow['immunity'] ?? 0),
            'groups.flags'     => $flags ? implode(', ', $flags) : \App\Core\Lang::t('common.none'),
        ];
        \App\Core\Discord::notify(
            'discord.admin_title',
            'discord.act_admin_removed',
            'reset',
            $fields,
            ['name' => $adminRow['player_name'] ?? 'Unknown', 'steamid' => $adminRow['player_steamid'] ?? '']
        );
        \App\Core\Logger::log(
            'admin.delete',
            $adminRow['player_steamid'] ?? $id,
            ($adminRow['player_name'] ?? 'Unknown') . ' | ' . \App\Core\Lang::t('common.group') . ': ' . ($adminRow['group_name'] ?? '-')
                . ' | ' . \App\Core\Lang::t('groups.flags') . ': ' . ($flags ? implode(', ', $flags) : \App\Core\Lang::t('common.none'))
        );
        $this->flash('success', 'Admin deleted.');
        $this->redirect('/admins');
    }

    /** The direct flags of an admin (for prefilling the edit modal). @return string[] */
    public function flagsFor(int $adminId): array
    {
        $p = $this->p();
        $rows = Database::all("SELECT flag FROM {$p}admins_flags WHERE admin_id = ?", [$adminId]);
        return array_column($rows, 'flag');
    }

    /** The CSS flags available in the UI. */
    private function flagList(): array
    {
        return \App\Core\Flags::all();
    }

                                                                                  
    private static function serverIdValue(mixed $serverId): ?int
    {
        $sid = (int) $serverId;
        return $sid > 0 ? $sid : null;
    }

                                                                               
    private static function groupReference(int $groupId): string
    {
        if ($groupId <= 0) {
            return '';
        }
        $p = \App\Core\Config::get('sa_prefix', 'sa_');
        $name = trim((string) Database::scalar("SELECT name FROM {$p}groups WHERE id = ?", [$groupId]));
        if ($name === '') {
            return '';
        }
        $reference = str_starts_with($name, '#') ? $name : '#' . $name;
        return preg_match('/^#[^\s]{1,190}$/', $reference) ? $reference : '';
    }

    /** Rewrite the normalized text field mirror and linked sa_admins_flags rows. @param string[] $flags */
    private function syncFlags(int $adminId, array $flags): void
    {
        $p = $this->p();
        Database::run("DELETE FROM {$p}admins_flags WHERE admin_id = ?", [$adminId]);
        if (!$flags) {
            return;
        }
        $values = implode(', ', array_fill(0, count($flags), '(?, ?)'));
        $params = [];
        foreach ($flags as $flag) {
            $params[] = $adminId;
            $params[] = $flag;
        }
        Database::run("INSERT INTO {$p}admins_flags (admin_id, flag) VALUES {$values}", $params);
    }

    /** Keep only direct @permissions; group references are added from group_id. @param array<mixed> $flags @return string[] */
    private function validFlags(array $flags): array
    {
        $out = [];
        foreach ($flags as $flag) {
            $flag = is_string($flag) ? trim($flag) : '';
            if (\App\Core\Flags::isValid($flag)) {
                $out[$flag] = true;
            }
        }
        return array_keys($out);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Discord;
use App\Core\Lang;
use App\Core\Logger;
use App\Models\SanctionBulkModel;

final class SanctionBulkController extends Controller
{
    public function bans(): void
    {
        $this->update('bans');
    }

    public function mutes(): void
    {
        $this->update('mutes');
    }

    private function update(string $kind): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();
        try {
            $updated = SanctionBulkModel::update($kind, $_POST, Auth::steamId(), (string) (Auth::user()['name'] ?? 'Web'));
        } catch (\InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/' . $kind);
        } catch (\RuntimeException $exception) {
            error_log('[CS2Panel] ' . $kind . ' bulk edit failed: ' . $exception->getMessage());
            $this->flash('error', Lang::t('sanctions.bulk_failed'));
            $this->redirect('/' . $kind);
        }

        $action = (string) ($_POST['bulk_action'] ?? 'edit');
        $mode = SanctionBulkModel::actionMode($action);
        if (!$updated) {
            $this->flash($mode === 'edit' ? 'success' : 'error', Lang::t($mode === 'edit' ? 'sanctions.bulk_no_changes' : 'sanctions.bulk_action_none'));
            $this->redirect('/' . $kind);
        }
        $labels = [
            'reason' => 'common.reason', 'duration' => 'common.duration',
            'server_id' => 'common.server',
        ];
        $fields = array_keys($_POST['apply'] ?? []);
        $fieldLabels = array_map(static fn(string $field): string => Lang::t($labels[$field]), $fields);
        $event = ($kind === 'bans' ? 'ban.' : 'mute.')
            . ($mode === 'create' ? 'create' : ($mode === 'lift' ? ($kind === 'bans' ? 'unban' : 'unmute') : 'update'));
        $ids = [];
        $sourceIds = [];
        foreach ($updated as $change) {
            $record = $change['after'];
            $ids[] = '#' . (int) $record['id'];
            $sourceIds[] = '#' . (int) $change['before']['id'];
            Logger::log(
                $event,
                (string) ($record['player_steamid'] ?: ($record['player_ip'] ?? $record['id'])),
                'Bulk ' . $action . ' #' . (int) $change['before']['id'] . ' -> #' . (int) $record['id'] . ': ' . implode(', ', $fields)
            );
        }
        $summary = [
            'discord.affected_records' => (string) count($updated),
            'sanctions.bulk_records' => implode(', ', $ids),
        ];
        $skipped = count(array_unique(array_map('intval', $_POST['ids']))) - count($updated);
        if ($fieldLabels) $summary['sanctions.bulk_fields'] = implode(', ', $fieldLabels);
        if ($mode === 'create') $summary['sanctions.bulk_source_records'] = implode(', ', $sourceIds);
        if ($skipped > 0) $summary['sanctions.bulk_skipped'] = (string) $skipped;
        $sample = $updated[0]['after'];
        if (in_array('reason', $fields, true)) {
            $summary['common.reason'] = $mode === 'lift' ? trim((string) $_POST['reason']) : (string) $sample['reason'];
        }
        if ($mode !== 'edit') $summary['common.status'] = (string) $sample['status'];
        if ($kind === 'mutes' && $mode === 'create') $summary['common.type'] = (string) $sample['type'];
        if (in_array('duration', $fields, true)) {
            $summary['common.duration'] = (int) $sample['duration'] === 0
                ? Lang::t('common.permanent')
                : Lang::t('discord.minutes_n', [(int) $sample['duration']]);
        }
        if (in_array('server_id', $fields, true)) {
            $summary['common.server'] = $sample['server_name'] ?? Lang::t('common.global');
        }
        Discord::notify(
            $mode === 'lift'
                ? ($kind === 'bans' ? 'discord.unban_title' : 'discord.unmute_title')
                : ($kind === 'bans' ? 'discord.ban_title' : 'discord.mute_title'),
            'sanctions.bulk_action_' . $action,
            $mode === 'lift' ? ($kind === 'bans' ? 'unban' : 'unmute') : ($kind === 'bans' ? 'ban' : 'mute'),
            $summary
        );
        $this->flash('success', Lang::t('sanctions.bulk_action_saved', [
            'action' => Lang::t('sanctions.bulk_action_' . $action),
            'n' => count($updated),
            'skipped' => $skipped,
        ]));
        $this->redirect('/' . $kind);
    }
}

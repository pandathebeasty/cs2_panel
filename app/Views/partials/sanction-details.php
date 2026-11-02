<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;

/** @var array $sanction @var string $sanctionKind @var array|null $removal */
$detailsText = static fn(string $key): string => View::e(Lang::t($key));
$notRecorded = Lang::t('sanctions.not_recorded');
$recordedValue = static fn(mixed $value): string => View::e(
    trim((string) $value) !== '' ? $value : $notRecorded
);
$storedStatus = strtoupper((string) ($sanction['status'] ?? ''));
$detailsStatus = $storedStatus;
$endTimestamp = !empty($sanction['ends']) ? strtotime((string) $sanction['ends']) : false;
if ($storedStatus === 'ACTIVE' && ($sanctionKind === 'warns' || (int) $sanction['duration'] > 0)
    && $endTimestamp !== false && $endTimestamp <= time()) {
    $detailsStatus = 'EXPIRED';
}
$detailsType = match ($sanctionKind) {
    'bans' => 'BAN',
    'warns' => Lang::t('nav.warns'),
    default => (string) ($sanction['type'] ?? ''),
};
$detailsBadge = match ($detailsStatus) {
    'ACTIVE' => 'active',
    'UNBANNED' => 'unbanned',
    'UNMUTED' => 'unmuted',
    default => 'expired',
};
$lifted = in_array($storedStatus, ['UNBANNED', 'UNMUTED'], true);
?>
<div class="sanction-details-summary">
    <span class="sanction-details-id"><?= View::e($detailsType) ?> #<?= (int) $sanction['id'] ?></span>
    <span class="badge badge-<?= $detailsBadge ?>"><?= $recordedValue($detailsStatus) ?></span>
</div>
<?php if ($detailsStatus !== $storedStatus): ?>
    <p class="sanction-details-note"><?= $detailsText('sanctions.expired_by_time') ?></p>
<?php endif; ?>
<dl class="sanction-details-grid">
    <div><dt><?= $detailsText('common.player') ?></dt><dd><?= $recordedValue($sanction['player_name'] ?? null) ?></dd></div>
    <div><dt><?= $detailsText('common.steamid') ?></dt><dd class="mono"><?= !empty($sanction['player_steamid'])
        ? Steam::idLink((string) $sanction['player_steamid']) : View::e($notRecorded) ?></dd></div>
    <?php if ($sanctionKind === 'bans' && Auth::canModerate()): ?>
        <div><dt><?= $detailsText('common.ip') ?></dt><dd class="mono"><?= $recordedValue($sanction['player_ip'] ?? null) ?></dd></div>
    <?php endif; ?>
    <div><dt><?= $detailsText('common.server') ?></dt><dd><?= View::e(
        $sanction['server_name'] ?? (!empty($sanction['server_id']) ? '#' . (int) $sanction['server_id'] : Lang::t('common.global'))
    ) ?></dd></div>
    <div><dt><?= $detailsText('sanctions.issued_by') ?></dt><dd><?= Steam::nameLink(
        (string) ($sanction['admin_steamid'] ?? ''),
        trim((string) ($sanction['admin_name'] ?? '')) ?: $notRecorded
    ) ?></dd></div>
    <div><dt><?= $detailsText('sanctions.admin_steamid') ?></dt><dd class="mono"><?= $recordedValue($sanction['admin_steamid'] ?? null) ?></dd></div>
    <div><dt><?= $detailsText('common.created') ?></dt><dd><?= $recordedValue($sanction['created'] ?? null) ?></dd></div>
    <?php $showPermanent = (int) $sanction['duration'] === 0 && $sanctionKind !== 'warns' && !$lifted; ?>
    <div><dt><?= $detailsText('common.duration') ?></dt><dd><?= $showPermanent
        ? $detailsText('common.permanent')
        : ((int) $sanction['duration'] === 0 && $sanctionKind !== 'warns' ? '—' : (int) $sanction['duration'] . ' min') ?></dd></div>
    <div><dt><?= $detailsText('sanctions.recorded_end') ?></dt><dd><?= !empty($sanction['ends'])
        ? View::e($sanction['ends'])
        : ($showPermanent ? $detailsText('common.permanent') : View::e($notRecorded)) ?></dd></div>
    <div class="sanction-details-wide"><dt><?= $detailsText('common.reason') ?></dt><dd><?= $recordedValue($sanction['reason'] ?? null) ?></dd></div>
</dl>
<?php if ($lifted): ?>
    <section class="sanction-details-removal">
        <h4><?= $detailsText('sanctions.removal_details') ?></h4>
        <dl class="sanction-details-grid">
            <div><dt><?= $detailsText('sanctions.removed_by') ?></dt><dd><?= Steam::nameLink(
                (string) ($removal['removal_admin_steamid'] ?? ''),
                trim((string) ($removal['removal_admin_name'] ?? '')) ?: $notRecorded
            ) ?></dd></div>
            <div><dt><?= $detailsText('sanctions.admin_steamid') ?></dt><dd class="mono"><?= $recordedValue($removal['removal_admin_steamid'] ?? null) ?></dd></div>
            <div><dt><?= $detailsText('sanctions.removed_at') ?></dt><dd><?= $recordedValue($removal['created'] ?? $removal['created_at'] ?? null) ?></dd></div>
            <?php if ($removal !== null): ?>
                <div><dt><?= $detailsText('sanctions.removal_id') ?></dt><dd>#<?= (int) $removal['id'] ?> / <?= $detailsText('common.admin') ?> #<?= (int) $removal['admin_id'] ?></dd></div>
            <?php endif; ?>
            <div class="sanction-details-wide"><dt><?= $detailsText('sanctions.removal_reason') ?></dt><dd><?= $recordedValue($removal['reason'] ?? null) ?></dd></div>
        </dl>
        <?php if (empty($removal['removal_admin_name']) && empty($removal['removal_admin_steamid'])): ?>
            <p class="sanction-details-note"><?= $detailsText('sanctions.removal_unknown') ?></p>
        <?php endif; ?>
    </section>
<?php elseif ($storedStatus === 'EXPIRED' && $sanctionKind === 'warns'): ?>
    <p class="sanction-details-note"><?= $detailsText('sanctions.warning_history_hint') ?></p>
<?php endif; ?>

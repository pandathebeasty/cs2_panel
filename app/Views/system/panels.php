<?php
use App\Controllers\SystemController;
use App\Core\Lang;
use App\Core\View;
/**
 * @var array $checks @var array $backups @var ?array $rollback @var array $staff @var string $csrf @var array $system
 */
extract($system, EXTR_SKIP);
$s = $system['s'];
$csrfField = '<input type="hidden" name="csrf" value="' . View::e($csrf) . '">';
$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $c) { $counts[$c['status']]++; }
$score = count($checks) ? (int) round(100 * ($counts['ok'] + $counts['warn'] * 0.5) / count($checks)) : 0;
$groups = [];
foreach ($checks as $c) { $groups[$c['group']][] = $c; }
$weekdays = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
[$bkHour, $bkMin] = array_map('intval', explode(':', $s['backup_auto_time'] . ':0'));
$autoStatus = explode('|', $s['backup_auto_status']);
?>
    <!-- Health -->
    <div data-tabpanel="sys-health" style="display:none">
        <div class="x-health-summary">
            <div class="x-score" style="--score:<?= $score ?>" title="<?= $L('system.health_score') ?>">
                <svg viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15.9" class="x-score-bg"/><circle cx="18" cy="18" r="15.9" class="x-score-fg" pathLength="100"/></svg>
                <b><?= $score ?></b>
            </div>
            <div class="x-chip is-ok"><b><?= $counts['ok'] ?></b> <?= $L('system.ok') ?></div>
            <div class="x-chip is-warn"><b><?= $counts['warn'] ?></b> <?= $L('system.warn') ?></div>
            <div class="x-chip is-fail"><b><?= $counts['fail'] ?></b> <?= $L('system.fail') ?></div>
            <a class="btn btn-sm btn-ghost" href="<?= View::e(url('/health')) ?>"><?= $L('system.advanced_health') ?></a>
        </div>
        <div class="x-health-grid">
            <?php foreach ($groups as $group => $items): ?>
                <div class="card x-section">
                    <div class="card-title"><?= $L('system.group_' . $group) ?></div>
                    <ul class="x-checks">
                        <?php foreach ($items as $c): ?>
                            <li class="is-<?= View::e($c['status']) ?>">
                                <span class="x-check-ico" aria-hidden="true"></span>
                                <div class="x-check-body">
                                    <div class="x-check-row"><span><?= View::e($c['label']) ?></span><b class="mono"><?= View::e($c['value']) ?></b></div>
                                    <?php if ($c['status'] !== 'ok' && $c['hint'] !== ''): ?><p class="muted x-small"><?= View::e($c['hint']) ?></p><?php endif; ?>
                                </div>
                                <span class="visually-hidden"><?= $L('system.' . $c['status']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Backups -->
    <div data-tabpanel="sys-backups" style="display:none">
        <div class="x-two-col">
            <form class="card x-section" method="post" action="<?= View::e(url('/system/backups/schedule')) ?>">
                <?= $csrfField ?>
                <div class="card-title"><?= $L('system.schedule_title') ?></div>
                <div class="check" style="margin-bottom:12px"><input type="checkbox" name="backup_auto_enabled" id="bk_en" value="1" <?= $s['backup_auto_enabled'] ? 'checked' : '' ?>><label for="bk_en"><?= $L('system.schedule_enable') ?></label></div>
                <div class="form-row">
                    <div class="field"><label for="bk_freq"><?= $L('system.frequency') ?></label>
                        <select name="backup_auto_frequency" id="bk_freq" data-bk-freq>
                            <?php foreach (['hourly', 'daily', 'weekly'] as $f): ?>
                                <option value="<?= $f ?>" <?= $s['backup_auto_frequency'] === $f ? 'selected' : '' ?>><?= $L('system.freq_' . $f) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" data-bk-time><label for="bk_time"><?= $L('system.time') ?></label>
                        <input type="text" id="bk_time" name="backup_auto_time" value="<?= View::e(sprintf('%02d:%02d', $bkHour, $bkMin)) ?>" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" title="24-hour HH:MM" required style="width:90px">
                        <small class="muted x-small" data-bk-hourly-hint style="display:none"><?= $L('system.hourly_hint') ?></small>
                    </div>
                    <div class="field" data-bk-day><label for="bk_day"><?= $L('system.weekday') ?></label>
                        <select name="backup_auto_weekday" id="bk_day">
                            <?php foreach ($weekdays as $n => $d): ?>
                                <option value="<?= $n ?>" <?= $s['backup_auto_weekday'] === $n ? 'selected' : '' ?>><?= $L('system.day_' . $d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field"><label for="bk_keep"><?= $L('system.keep') ?></label><input type="number" id="bk_keep" name="backup_auto_keep" min="1" max="365" value="<?= (int) $s['backup_auto_keep'] ?>"></div>
                    <div class="field" style="align-self:end"><div class="check"><input type="checkbox" id="bk_gz" name="backup_auto_compress" value="1" <?= $s['backup_auto_compress'] ? 'checked' : '' ?>><label for="bk_gz"><?= $L('system.compress') ?></label></div></div>
                </div>
                <p class="muted x-small"><?= $L('system.schedule_hint') ?></p>
                <div class="x-expire">
                    <div class="check"><input type="checkbox" id="bk_exp" name="backup_expire_enabled" value="1" data-bk-expire <?= $s['backup_max_age_days'] > 0 ? 'checked' : '' ?>><label for="bk_exp"><?= $L('system.expire_enable') ?></label></div>
                    <div class="field" data-bk-expire-days><label for="bk_age"><?= $L('system.expire_days') ?></label><input type="number" id="bk_age" name="backup_max_age_days" min="1" max="3650" value="<?= $s['backup_max_age_days'] > 0 ? (int) $s['backup_max_age_days'] : 30 ?>"></div>
                    <p class="muted x-small"><?= $L('system.expire_hint') ?></p>
                </div>
                <?php if ($s['backup_auto_enabled'] && ($autoStatus[1] ?? '') !== ''): ?>
                    <p class="x-small <?= $autoStatus[0] === 'ok' ? 'x-text-ok' : 'x-text-fail' ?>"><?= $L('system.last_auto') ?>: <?= View::e(date('Y-m-d H:i', (int) $autoStatus[1])) ?> · <?= View::e($autoStatus[0]) ?></p>
                <?php endif; ?>
                <button class="btn btn-primary" type="submit"><?= $L('common.save') ?></button>
            </form>

            <div class="card x-section">
                <div class="card-title x-title-row"><?= $L('system.backups_title') ?>
                    <form method="post" action="<?= View::e(url('/system/backups/create')) ?>" data-busy>
                        <?= $csrfField ?>
                        <button class="btn btn-sm btn-primary" type="submit"><?= $L('system.backup_now') ?></button>
                    </form>
                </div>
                <?php if (!$backups): ?>
                    <div class="x-empty"><?= $L('system.no_backups') ?></div>
                <?php else: ?>
                    <ul class="x-file-list">
                        <?php foreach ($backups as $b): ?>
                            <li>
                                <span class="x-file-type is-<?= View::e($b['type']) ?>"><?= $L('system.type_' . $b['type']) ?></span>
                                <div class="x-file-name">
                                    <b class="mono" title="<?= View::e($b['name']) ?>"><?= View::e($b['name']) ?></b>
                                    <span class="muted x-small"><?= View::e(date('Y-m-d H:i', $b['mtime'])) ?> · <?= View::e(SystemController::human($b['size'])) ?></span>
                                </div>
                                <div class="x-file-actions">
                                    <a class="btn btn-sm btn-ghost" href="<?= View::e(url('/system/backups/download?file=' . rawurlencode($b['name']))) ?>" title="<?= $L('system.download') ?>" aria-label="<?= $L('system.download') ?>">⤓</a>
                                    <?php if ($b['type'] !== 'code'): ?>
                                        <form method="post" action="<?= View::e(url('/system/backups/restore')) ?>"
                                              data-confirm="<?= $L('system.restore_confirm') ?>" data-confirm-type="RESTORE" data-confirm-field="confirm" data-confirm-danger>
                                            <?= $csrfField ?><input type="hidden" name="file" value="<?= View::e($b['name']) ?>"><input type="hidden" name="confirm" value="">
                                            <button class="btn btn-sm" type="submit"><?= $L('system.restore') ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= View::e(url('/system/backups/delete')) ?>" data-confirm="<?= $L('system.delete_confirm') ?>" data-confirm-danger>
                                        <?= $csrfField ?><input type="hidden" name="file" value="<?= View::e($b['name']) ?>">
                                        <button class="btn btn-sm btn-danger" type="submit" aria-label="<?= $L('common.delete') ?>">✕</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Security -->
    <div data-tabpanel="sys-security" style="display:none">
        <div class="x-two-col">
            <form class="card x-section" method="post" action="<?= View::e(url('/system/security')) ?>">
                <?= $csrfField ?>
                <div class="card-title"><?= $L('system.security_title') ?></div>
                <div class="check" style="margin-bottom:6px"><input type="checkbox" id="sec_2fa" name="security_require_2fa" value="1" <?= $s['security_require_2fa'] ? 'checked' : '' ?>><label for="sec_2fa"><?= $L('system.require_2fa') ?></label></div>
                <p class="muted x-small"><?= $L('system.require_2fa_hint') ?></p>
                <div class="field"><label for="aud_ret"><?= $L('system.audit_retention') ?></label><input type="number" id="aud_ret" name="audit_retention_days" min="0" max="3650" value="<?= (int) $s['audit_retention_days'] ?>"></div>
                <p class="muted x-small"><?= $L('system.audit_retention_hint') ?></p>
                <div class="x-inline-actions">
                    <button class="btn btn-primary" type="submit"><?= $L('common.save') ?></button>
                    <a class="btn btn-ghost" href="<?= View::e(url('/account/security')) ?>"><?= $L('2fa.security_title') ?></a>
                </div>
            </form>
            <div class="card x-section">
                <div class="card-title"><?= $L('system.staff_2fa') ?></div>
                <?php if (!$staff): ?>
                    <div class="x-empty"><?= $L('player.none') ?></div>
                <?php else: ?>
                    <ul class="x-file-list">
                        <?php foreach ($staff as $m): ?>
                            <li>
                                <span class="x-file-type <?= $m['twofa'] ? 'is-ok' : 'is-warn' ?>"><?= $m['twofa'] ? '2FA' : '—' ?></span>
                                <div class="x-file-name"><b><?= View::e($m['name']) ?></b><span class="muted x-small mono"><?= View::e($m['steamid']) ?> · <?= View::e($m['role']) ?></span></div>
                                <?php if ($m['twofa']): ?>
                                    <form method="post" action="<?= View::e(url('/system/2fa/' . $m['steamid'] . '/reset')) ?>" data-confirm="<?= $L('system.reset_2fa_confirm') ?>" data-confirm-danger>
                                        <?= $csrfField ?><button class="btn btn-sm" type="submit"><?= $L('system.reset_2fa') ?></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Server monitoring -->
    <div data-tabpanel="sys-monitor" style="display:none">
        <form class="card x-section" method="post" action="<?= View::e(url('/system/monitoring')) ?>" style="max-width:900px">
            <?= $csrfField ?>
            <div class="card-title x-title-row"><?= $L('system.monitor_title') ?> <a class="btn btn-sm btn-ghost" href="<?= View::e(url('/servers')) ?>#monitor"><?= $L('monitor.title') ?> →</a></div>
            <div class="check" style="margin-bottom:12px"><input type="checkbox" id="mon_en" name="monitor_enabled" value="1" <?= $s['monitor_enabled'] ? 'checked' : '' ?>><label for="mon_en"><?= $L('system.monitor_enable') ?></label></div>
            <div class="form-row">
                <div class="field"><label for="mon_int"><?= $L('system.monitor_interval') ?></label><input type="number" id="mon_int" name="monitor_interval" min="1" max="60" value="<?= (int) $s['monitor_interval'] ?>"></div>
                <div class="field"><label for="mon_down"><?= $L('system.monitor_down_after') ?></label><input type="number" id="mon_down" name="monitor_down_after" min="1" max="30" value="<?= (int) $s['monitor_down_after'] ?>"></div>
                <div class="field"><label for="mon_ret"><?= $L('system.monitor_retention') ?></label><input type="number" id="mon_ret" name="monitor_retention_days" min="1" max="365" value="<?= (int) $s['monitor_retention_days'] ?>"></div>
            </div>
            <div class="field"><label for="mon_hook"><?= $L('system.monitor_webhook') ?></label><input type="url" id="mon_hook" name="monitor_webhook" value="<?= View::e($s['monitor_webhook']) ?>" placeholder="https://discord.com/api/webhooks/..."></div>
            <p class="muted x-small"><?= $L('system.monitor_hint') ?></p>
            <button class="btn btn-primary" type="submit"><?= $L('common.save') ?></button>
        </form>
    </div>

    <!-- Update rollback -->
    <div data-tabpanel="updates" style="display:none">
        <div class="card x-section" style="margin-top:18px">
            <div class="card-title"><?= $L('system.rollback_title') ?></div>
            <?php if (!$rollback): ?>
                <div class="x-empty"><?= $L('system.rollback_none') ?></div>
            <?php else: ?>
                <div class="x-rollback">
                    <div class="x-version-pill">v<?= View::e($rollback['to']) ?></div>
                    <span class="x-arrow" aria-hidden="true">→</span>
                    <div class="x-version-pill is-target">v<?= View::e($rollback['from']) ?></div>
                </div>
                <p class="muted x-small"><?= View::e(Lang::t('system.rollback_info', ['date' => date('Y-m-d H:i', $rollback['at']), 'file' => $rollback['code_backup']])) ?></p>
                <form method="post" action="<?= View::e(url('/system/rollback')) ?>"
                      data-confirm="<?= $L('system.rollback_confirm') ?>" data-confirm-type="ROLLBACK" data-confirm-field="confirm" data-confirm-danger data-busy>
                    <?= $csrfField ?><input type="hidden" name="confirm" value="">
                    <?php if ($rollback['has_db']): ?>
                        <div class="check" style="margin:8px 0 6px"><input type="checkbox" id="rb_db" name="restore_db" value="1"><label for="rb_db"><?= View::e(Lang::t('system.rollback_db', ['file' => $rollback['db_backup']])) ?></label></div>
                        <p class="muted x-small"><?= $L('system.rollback_db_hint') ?></p>
                    <?php endif; ?>
                    <button class="btn btn-danger" type="submit"><?= $L('system.rollback_btn') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<script>
(function () {
    var freq = document.querySelector('[data-bk-freq]');
    if (!freq) return;
    function sync() {
        var v = freq.value;
        document.querySelector('[data-bk-day]').style.display = v === 'weekly' ? '' : 'none';
        document.querySelector('[data-bk-hourly-hint]').style.display = v === 'hourly' ? 'block' : 'none';
    }
    freq.addEventListener('change', sync);
    sync();
    var exp = document.querySelector('[data-bk-expire]');
    var days = document.querySelector('[data-bk-expire-days]');
    if (exp && days) {
        var syncExp = function () { days.style.display = exp.checked ? '' : 'none'; };
        exp.addEventListener('change', syncExp);
        syncExp();
    }
})();
</script>

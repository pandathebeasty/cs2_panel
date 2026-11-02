<?php
use App\Core\Lang;
use App\Core\View;
/** @var array $servers @var bool $canRcon @var bool $canManage @var bool $canModerate @var string $csrf */
$title = Lang::t('nav.servers');
$L = fn(string $k) => View::e(Lang::t($k));
$canModerate = $canModerate ?? false;
$rconServers = array_values(array_filter($servers, static fn($s) => !empty($s['rcon_password'])));
?>
<div class="tab-host">
    <div class="tabs-row">
        <div class="tabs" style="display:inline-flex">
            <button type="button" data-tab="overview" class="active"><?= $L('servers.overview') ?></button>
            <?php if ($canModerate): ?><button type="button" data-tab="players"><?= $L('servers.players_tab') ?></button><?php endif; ?>
            <?php if ($canRcon): ?><button type="button" data-tab="rcon"><?= $L('servers.rcon') ?></button><?php endif; ?>
        </div>
        <?php if ($canManage): ?>
            <button class="btn btn-primary" data-modal="modal-server-new">+ <?= $L('common.new') ?></button>
        <?php endif; ?>
    </div>

    <!-- Overview tab -->
    <div data-tabpanel="overview">
        <?php if (!$servers): ?>
            <div class="card"><div class="empty"><?= $L('servers.none') ?><br><span class="muted"><?= $L('servers.none_hint') ?></span></div></div>
        <?php else: ?>
            <div class="grid server-grid">
                <?php foreach ($servers as $s): ?>
                    <?php
                    $live    = $s['live'] ?? ['online' => false];
                    $online  = !empty($live['online']);
                    $mapSlug = \App\Core\ServerMap::slug($live['map'] ?? null);
                    $mapName = \App\Core\ServerMap::label($live['map'] ?? null);
                    $mapImg  = $s['map_image'] ?? null;
                    $players = $live['players'] ?? null;
                    $maxP    = $live['max_players'] ?? null;
                    // Deterministic gradient fallback derived from the map slug.
                    $hue = $mapSlug !== '' ? (crc32($mapSlug) % 360) : 220;
                    ?>
                    <div class="card server-card <?= $online ? 'is-online' : 'is-offline' ?>" style="padding:0;overflow:hidden">
                        <div class="server-banner" style="<?= $mapImg ? "background-image:url('" . View::e($mapImg) . "')" : 'background:linear-gradient(135deg,hsl(' . $hue . ',55%,32%),hsl(' . (($hue + 40) % 360) . ',60%,18%))' ?>">
                            <span class="server-status" title="<?= $online ? $L('servers.online') : $L('servers.offline') ?>">
                                <span class="dot"></span><?= $online ? $L('servers.online') : $L('servers.offline') ?>
                            </span>
                            <?php if ($online && $mapSlug !== ''): ?>
                                <span class="server-map-tag"><?= View::e($mapName) ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="padding:16px">
                            <div class="server-name" title="<?= View::e($s['hostname']) ?>"><?= View::e($online && !empty($live['name']) ? $live['name'] : $s['hostname']) ?></div>
                            <div class="mono muted" style="font-size:12.5px;margin:2px 0 12px"><?= View::e($s['address']) ?></div>

                            <div class="server-meta">
                                <div class="server-stat">
                                    <span class="k"><?= $L('servers.players') ?></span>
                                    <span class="v"><?= $online && $players !== null ? (int) $players . ' / ' . (int) $maxP : '—' ?></span>
                                </div>
                                <div class="server-stat">
                                    <span class="k"><?= $L('servers.map') ?></span>
                                    <span class="v"><?= $online ? View::e($mapName) : '—' ?></span>
                                </div>
                                <div class="server-stat">
                                    <span class="k"><?= $L('servers.rcon') ?></span>
                                    <span class="v"><span class="badge <?= !empty($s['rcon_password']) ? 'badge-active' : 'badge-expired' ?>"><?= !empty($s['rcon_password']) ? $L('servers.ready') : $L('servers.not_set') ?></span></span>
                                </div>
                            </div>

                            <?php if (!$online): ?>
                                <p class="muted" style="font-size:12px;margin:10px 0 0"><?= $L('servers.offline_hint') ?></p>
                            <?php endif; ?>

                            <div class="server-actions">
                                <?php if (!empty($s['connect_url'])): ?>
                                    <a class="btn btn-primary btn-sm" href="<?= View::e($s['connect_url']) ?>">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                        <?= $L('servers.connect') ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($canManage): ?>
                                    <button class="btn btn-sm"
                                        data-modal="modal-server-edit"
                                        data-set-hostname="<?= View::e($s['hostname']) ?>"
                                        data-set-address="<?= View::e($s['address']) ?>"
                                        onclick="document.getElementById('server-edit-form').action=(window.CS2_BASE||'')+'/servers/<?= (int) $s['id'] ?>/update'"><?= $L('common.edit') ?></button>
                                    <form method="post" action="/servers/<?= (int) $s['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('servers.delete_confirm') ?>">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <button class="btn btn-sm btn-danger" type="submit"><?= $L('common.delete') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Live players tab -->
    <?php if ($canModerate): ?>
    <div data-tabpanel="players" style="display:none">
        <?php if (!$rconServers): ?>
            <div class="card"><div class="empty"><?= $L('servers.none_rcon') ?></div></div>
        <?php else: ?>
            <?php foreach ($rconServers as $s): ?>
                <div class="card live-players" data-server="<?= (int) $s['id'] ?>" data-max="<?= isset($s['live']['max_players']) ? (int) $s['live']['max_players'] : '' ?>" style="margin-bottom:18px">
                    <div class="toolbar" style="margin-bottom:12px">
                        <div>
                            <div style="font-weight:700;font-size:15px"><?= View::e($s['hostname']) ?></div>
                            <div class="mono muted" style="font-size:12.5px"><?= View::e($s['address']) ?></div>
                        </div>
                        <div class="spacer"></div>
                        <span class="muted lp-count"></span>
                        <button type="button" class="btn btn-sm lp-refresh"><?= $L('common.refresh') ?></button>
                    </div>
                    <div class="lp-body"><div class="empty"><?= $L('common.loading') ?></div></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- RCON tab -->
    <div data-tabpanel="rcon" style="display:none">
        <?php if (!$canRcon): ?>
            <div class="card"><div class="empty"><?= $L('servers.no_perm') ?></div></div>
        <?php elseif (!$servers): ?>
            <div class="card"><div class="empty"><?= $L('servers.none_rcon') ?></div></div>
        <?php else: ?>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">
                <?php foreach ($servers as $s): ?>
                    <div class="card">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                            <div>
                                <div style="font-weight:700;font-size:16px"><?= View::e($s['hostname']) ?></div>
                                <div class="mono"><?= View::e($s['address']) ?></div>
                            </div>
                            <span class="badge <?= !empty($s['rcon_password']) ? 'badge-active' : 'badge-expired' ?>"><?= !empty($s['rcon_password']) ? $L('servers.rcon_ready') : $L('servers.no_rcon') ?></span>
                        </div>
                        <?php if (!empty($s['rcon_password'])): ?>
                            <form class="rcon-form" action="/servers/<?= (int) $s['id'] ?>/rcon" method="post" style="display:flex;gap:8px;margin-bottom:10px">
                                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                <input type="text" name="command" placeholder="<?= $L('servers.rcon_ph') ?>" autocomplete="off">
                                <button class="btn btn-primary" type="submit"><?= $L('servers.send') ?></button>
                            </form>
                            <div class="rcon-out"><?= $L('servers.rcon_hint') ?></div>
                        <?php else: ?>
                            <div class="muted"><?= $L('servers.rcon_needpass') ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
<!-- New server modal -->
<div class="modal-overlay" id="modal-server-new">
    <div class="modal">
        <form method="post" action="/servers">
            <div class="modal-head"><h3><?= $L('servers.add_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('servers.hostname') ?></label><input type="text" name="hostname" placeholder="My CS2 Server" required></div>
                <div class="field"><label><?= $L('servers.address') ?> (<?= $L('servers.address_ph') ?>)</label><input type="text" name="address" placeholder="127.0.0.1:27015" required></div>
                <div class="field"><label><?= $L('servers.rcon_pass') ?></label><input type="password" name="rcon_password" placeholder="<?= $L('common.optional') ?>"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.add') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit server modal -->
<div class="modal-overlay" id="modal-server-edit">
    <div class="modal">
        <form method="post" action="/servers/0/update" id="server-edit-form">
            <div class="modal-head"><h3><?= $L('servers.edit_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('servers.hostname') ?></label><input type="text" name="hostname" required></div>
                <div class="field"><label><?= $L('servers.address') ?> (<?= $L('servers.address_ph') ?>)</label><input type="text" name="address" required></div>
                <div class="field"><label><?= $L('servers.rcon_pass') ?></label><input type="password" name="rcon_password" placeholder="<?= $L('servers.rcon_keep') ?>"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canModerate && $rconServers): ?>
<!-- Live moderation: duration + reason for the selected player -->
<div class="modal-overlay" id="modal-live-action">
    <div class="modal" style="max-width:420px">
        <form id="live-action-form">
            <div class="modal-head"><h3 id="live-action-title"></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="action" value="">
                <input type="hidden" name="userid" value="">
                <input type="hidden" name="steamid" value="">
                <input type="hidden" name="player_name" value="">
                <p class="muted" id="live-action-target" style="margin:0 0 14px"></p>
                <div class="field" id="live-action-duration">
                    <label><?= $L('common.duration') ?></label>
                    <input type="number" name="minutes" value="60" min="0" max="525600">
                    <p class="hint" style="margin-top:4px"><?= $L('servers.duration_hint') ?></p>
                </div>
                <div class="field">
                    <label><?= $L('common.reason') ?></label>
                    <input type="text" name="reason" maxlength="120" placeholder="<?= $L('common.optional') ?>">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-danger"><?= $L('servers.execute') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var base = window.CS2_BASE || '';
    var csrf = <?= json_encode($csrf) ?>;
    var T = <?= json_encode([
        'kick'    => Lang::t('servers.act_kick'),
        'ban'     => Lang::t('servers.act_ban'),
        'mute'    => Lang::t('servers.act_mute'),
        'gag'     => Lang::t('servers.act_gag'),
        'silence' => Lang::t('servers.act_silence'),
        'none'    => Lang::t('servers.no_players'),
        'player'  => Lang::t('common.player'),
        'time'    => Lang::t('servers.connected_for'),
        'ping'    => 'Ping',
        'actions' => Lang::t('common.actions'),
        'failed'  => Lang::t('servers.action_failed'),
        'done'    => Lang::t('servers.action_done'),
    ], JSON_UNESCAPED_UNICODE) ?>;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function renderPlayers(card, players, max) {
        var body = card.querySelector('.lp-body');
        // Same slot count as the server card (A2S); RCON status is the fallback.
        if (card.dataset.max === '' && max != null) card.dataset.max = String(max);
        card.querySelector('.lp-count').textContent = players.length + ' / ' + (card.dataset.max || '—');
        if (!players.length) {
            body.innerHTML = '<div class="empty">' + esc(T.none) + '</div>';
            return;
        }
        var rows = players.map(function (p) {
            var name = p.profile_url
                ? '<a class="player-cell" href="' + esc(p.profile_url) + '" target="_blank" rel="noopener">'
                    + '<img class="player-avatar" src="' + esc(p.avatar) + '" alt="" loading="lazy">'
                    + '<span class="pname">' + esc(p.name) + '</span></a>'
                : '<span class="pname">' + esc(p.name) + '</span>';
            var btns = ['kick', 'ban', 'mute', 'gag', 'silence'].map(function (a) {
                return '<button type="button" class="btn btn-sm lp-act" data-act="' + a + '"'
                    + ' data-userid="' + esc(p.userid) + '" data-steamid="' + esc(p.steamid64) + '"'
                    + ' data-name="' + esc(p.name) + '">' + esc(T[a]) + '</button>';
            }).join(' ');
            return '<tr><td class="mono">#' + esc(p.userid) + '</td><td>' + name + '</td>'
                + '<td class="mono">' + esc(p.steamid || '—') + '</td>'
                + '<td class="muted">' + esc(p.time || '—') + '</td>'
                + '<td class="muted">' + esc(p.ping || '—') + '</td>'
                + '<td style="white-space:nowrap">' + btns + '</td></tr>';
        }).join('');
        body.innerHTML = '<div class="table-wrap"><table><thead><tr>'
            + '<th>ID</th><th>' + esc(T.player) + '</th><th>SteamID</th><th>' + esc(T.time) + '</th>'
            + '<th>' + esc(T.ping) + '</th><th>' + esc(T.actions) + '</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    function load(card) {
        var body = card.querySelector('.lp-body');
        fetch(base + '/servers/' + card.dataset.server + '/players', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) { renderPlayers(card, d.players || [], d.max); }
                else { body.innerHTML = '<div class="empty">' + esc((d && d.error) || T.failed) + '</div>'; }
            })
            .catch(function () { body.innerHTML = '<div class="empty">' + esc(T.failed) + '</div>'; });
    }

    var cards = Array.prototype.slice.call(document.querySelectorAll('.live-players'));
    cards.forEach(function (card) {
        card.querySelector('.lp-refresh').addEventListener('click', function () { load(card); });
    });

    // Only query the servers once the tab is actually opened.
    var loaded = false;
    document.querySelectorAll('.tabs [data-tab="players"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (loaded) return;
            loaded = true;
            cards.forEach(load);
        });
    });

    var modal = document.getElementById('modal-live-action');
    var form = document.getElementById('live-action-form');
    var currentCard = null;

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.lp-act');
        if (!btn) return;
        currentCard = btn.closest('.live-players');
        var act = btn.dataset.act;
        form.action.value = act;
        form.userid.value = btn.dataset.userid;
        form.steamid.value = btn.dataset.steamid || '';
        form.player_name.value = btn.dataset.name || '';
        form.reason.value = '';
        document.getElementById('live-action-title').textContent = T[act];
        document.getElementById('live-action-target').textContent = btn.dataset.name + ' (#' + btn.dataset.userid + ')';
        // Kick happens immediately, there is nothing to schedule.
        document.getElementById('live-action-duration').style.display = act === 'kick' ? 'none' : '';
        modal.classList.add('open');
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!currentCard) return;
        var fd = new FormData(form);
        fd.set('csrf', csrf);
        fetch(base + '/servers/' + currentCard.dataset.server + '/player-action', {
            method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                modal.classList.remove('open');
                if (!d || !d.ok) { window.alert((d && d.error) || T.failed); return; }
                load(currentCard);
            })
            .catch(function () { window.alert(T.failed); });
    });
})();
</script>
<?php endif; ?>

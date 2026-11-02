<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
use App\Models\ChatLogModel;

$title = Lang::t('chat_logs.title');
$L = static fn(string $key, array $params = []): string => View::e(Lang::t($key, $params));
$pg = $logs;
$liveSearchKey = $enabled ? 'chat-logs' : null;
$teamBadges = ['unassigned' => 'badge-expired', 'spec' => 'badge-expired', 't' => 'badge-gag', 'ct' => 'badge-unbanned', 'unknown' => 'badge-expired'];
?>
<?php if (!$enabled): ?>
    <div class="card">
        <div class="card-title"><?= $L('chat_logs.disabled') ?></div>
        <p class="hint"><?= $L('chat_logs.disabled_hint') ?></p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-title"><?= $L('chat_logs.filters') ?></div>
        <form method="get" action="<?= View::e(url('/chat-logs')) ?>" class="chat-log-filters" data-live-search="chat-logs">
            <div class="field chat-log-search">
                <label for="chat-search"><?= $L('common.search') ?></label>
                <input type="search" id="chat-search" name="search" maxlength="255" value="<?= View::e($filters['search']) ?>" placeholder="<?= $L('chat_logs.search_hint') ?>">
            </div>
            <div class="field">
                <label for="chat-server"><?= $L('chat_logs.server') ?></label>
                <select id="chat-server" name="server_id">
                    <option value=""><?= $L('common.all') ?></option>
                    <?php foreach ($serverIds as $serverId): ?>
                        <option value="<?= View::e($serverId) ?>"<?= $filters['server_id'] === $serverId ? ' selected' : '' ?>><?= View::e($serverId) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="chat-team"><?= $L('chat_logs.team') ?></label>
                <select id="chat-team" name="team">
                    <option value=""><?= $L('common.all') ?></option>
                    <?php foreach (ChatLogModel::TEAMS as $team): ?>
                        <option value="<?= $team ?>"<?= $filters['team'] === $team ? ' selected' : '' ?>><?= $L('chat_logs.team_' . $team) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="chat-channel"><?= $L('chat_logs.channel') ?></label>
                <select id="chat-channel" name="channel">
                    <option value=""><?= $L('common.all') ?></option>
                    <?php foreach (ChatLogModel::CHANNELS as $channel): ?>
                        <option value="<?= $channel ?>"<?= $filters['channel'] === $channel ? ' selected' : '' ?>><?= $L('chat_logs.channel_' . $channel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="chat-per-page"><?= $L('chat_logs.per_page') ?></label>
                <select id="chat-per-page" name="per_page">
                    <?php foreach (ChatLogModel::PER_PAGE_OPTIONS as $size): ?>
                        <option value="<?= $size ?>"<?= $perPage === $size ? ' selected' : '' ?>><?= $size ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="chat-log-filter-actions">
                <button type="submit" class="btn btn-primary"><?= $L('common.filter') ?></button>
                <a class="btn" href="<?= View::e(url('/chat-logs')) ?>" data-live-search-link><?= $L('common.reset') ?></a>
            </div>
        </form>
        <p class="hint"><?= $L('chat_logs.read_only') ?></p>
    </div>

    <?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
    <div class="live-search-results" id="search-results-chat-logs" data-live-search-results="chat-logs" tabindex="-1">
    <?php if ($error !== null): ?>
        <div class="flash flash-error" role="alert"><?= View::e($error) ?></div>
    <?php elseif ($pg !== null): ?>
        <div class="chat-log-summary">
            <p class="hint"><?= $L('chat_logs.showing', [
                'from' => $pg->total ? ($pg->page - 1) * $pg->perPage + 1 : 0,
                'to' => min($pg->page * $pg->perPage, $pg->total),
                'total' => number_format($pg->total),
            ]) ?></p>
            <a class="btn btn-sm" href="<?= View::e(url('/chat-logs') . '?' . http_build_query($filters + ['per_page' => $pg->perPage, 'page' => $pg->page])) ?>" data-live-search-link><?= $L('common.refresh') ?></a>
        </div>
        <div class="card chat-log-results">
            <?php if (!$pg->items): ?>
                <div class="empty"><?= $L('chat_logs.none') ?></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="chat-log-table">
                        <thead><tr>
                            <th><?= $L('chat_logs.when') ?></th>
                            <th><?= $L('chat_logs.player') ?></th>
                            <th><?= $L('chat_logs.server') ?> / <?= $L('chat_logs.map') ?></th>
                            <th><?= $L('chat_logs.channel') ?></th>
                            <th><?= $L('chat_logs.message') ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($pg->items as $entry): ?>
                            <?php
                            $type = ChatLogModel::chatType((int) $entry['where']);
                            $steamId = Steam::toSteamId64Any((string) $entry['steam_id']);
                            $name = trim((string) $entry['player_name']) ?: (string) $entry['steam_id'];
                            ?>
                            <tr data-chat-id="<?= (int) $entry['id'] ?>">
                                <td class="muted"><?= View::e($entry['date']) ?></td>
                                <td class="chat-log-player">
                                    <?php if ($steamId !== null && Steam::isValidSteamId64($steamId)): ?>
                                        <a href="<?= View::e(Steam::profileUrl($steamId)) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($name) ?></a>
                                    <?php else: ?>
                                        <strong><?= View::e($name) ?></strong>
                                    <?php endif; ?>
                                    <span class="mono"><?= View::e($entry['steam_id']) ?></span>
                                </td>
                                <td class="chat-log-source">
                                    <span><?= View::e($entry['server_id'] !== '' ? $entry['server_id'] : Lang::t('chat_logs.unspecified')) ?></span>
                                    <span class="muted"><?= View::e($entry['map_name']) ?></span>
                                </td>
                                <td>
                                    <div class="chat-log-tags">
                                        <span class="badge <?= $teamBadges[$type['team']] ?>"><?= $L('chat_logs.team_' . $type['team']) ?></span>
                                        <span class="badge badge-expired"><?= $L('chat_logs.channel_' . $type['channel']) ?></span>
                                    </div>
                                </td>
                                <td><div class="chat-log-text" tabindex="0"><?= View::e($entry['message']) ?></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php require BASE_PATH . '/app/Views/partials/pagination.php'; ?>
    <?php endif; ?>
    </div>
<?php endif; ?>

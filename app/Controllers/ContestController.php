<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DevMode;
use App\Core\Discord;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Settings;
use App\Models\ContestModel;
use App\Models\VipModel;
use App\Models\StatsBackend;

/**
 * Rank-based contests: create, list/history, delete, and process winners.
 *
 * Because shared hosting has no cron, due contests (whose end time has passed)
 * and history retention cleanup are processed lazily here, on each page load,
 * throttled to run its heavy pass at most once per minute per request wave.
 */
final class ContestController extends Controller
{
    public function index(): void
    {
        // Contests are visible read-only to everyone logged in; only managers
        // see the create/edit/delete controls (gated in the view via $canManage).
        Auth::requireLogin();

        if (!Settings::bool('contests_enabled', true)) {
            $this->view('contests/disabled', [], 'main');
            return;
        }
        // Tables missing (not migrated) — show the disabled/help page, unless dev.
        if (!ContestModel::available() && !DevMode::enabled()) {
            $this->view('contests/disabled', ['needsMigration' => true], 'main');
            return;
        }

        // Periodic maintenance (contest processing, order cleanup, ...) now runs
        // via the centralized, throttled Maintenance::lazyTick() in the front
        // controller on every page load — no per-page hook needed here.

        $contests = ContestModel::all();
        // Attach winners to each contest for inline display.
        foreach ($contests as &$c) {
            $c['winners'] = ContestModel::winners((int) $c['id']);
        }
        unset($c);

        $this->view('contests/index', [
            'contests'  => $contests,
            'vipGroups' => VipModel::configuredGroups(),
            'zenithOn'  => StatsBackend::enabled(),
            'webhookSet' => Discord::contestEnabled(),
            'retention' => ContestModel::retentionDays(),
            'canManage' => Auth::canManage(),
            'csrf'      => $this->csrfToken(),
        ]);
    }

    /** Contest detail + history page. Managers can edit/delete; others view only. */
    public function show(string $id): void
    {
        Auth::requireLogin();
        // Only numeric contest ids are valid here. A non-numeric path segment
        // (e.g. a stale "/contests/cron") is a real 404, not a contest lookup.
        if (!ctype_digit($id)) {
            http_response_code(404);
            $this->view('errors/404', [], 'main');
            return;
        }
        if (!Settings::bool('contests_enabled', true) || !ContestModel::available()) {
            $this->redirect('/contests');
        }
        $cid = (int) $id;
        $contest = ContestModel::find($cid);
        if ($contest === null) {
            $this->flash('error', 'Contest not found.');
            $this->redirect('/contests');
        }
        // While a contest is still active we show a READ-ONLY preview of the
        // current leaders (nothing is stored, nothing is grantable yet). The
        // stored winner rows exist only after the contest is finalized.
        $isActive = ($contest['status'] ?? '') === 'active';
        $winners = $isActive ? ContestModel::previewLeaders($cid) : ContestModel::winners($cid);

        $this->view('contests/show', [
            'contest'   => $contest,
            'winners'   => $winners,
            'isPreview' => $isActive, // true => read-only standings, no Grant
            'prizes'    => ContestModel::decodePrizes($contest['prizes'] ?? null),
            'canManage' => Auth::canManage(),
            'isRoot'    => Auth::isRoot(),
            'vipGroups' => VipModel::configuredGroups(),
            'csrf'      => $this->csrfToken(),
        ]);
    }

    /**
     * Read per-position prize inputs from POST. The form posts
     * prize_group[POS], prize_days[POS] and prize_credits[POS] for each place.
     * @return array<int,array{group:string,days:int,credits:int}>
     */
    private function readPrizes(): array
    {
        $groups  = (array) ($this->input('prize_group', []) ?: []);
        $days    = (array) ($this->input('prize_days', []) ?: []);
        $credits = (array) ($this->input('prize_credits', []) ?: []);
        $prizes = [];
        // Positions can come from either the group or the credits inputs, so a
        // credits-only place (no VIP group) is still captured.
        $positions = array_unique(array_map('intval', array_merge(array_keys($groups), array_keys($credits))));
        foreach ($positions as $pos) {
            if ($pos < 1) {
                continue;
            }
            $g  = trim((string) ($groups[$pos] ?? ''));
            $cr = max(0, (int) ($credits[$pos] ?? 0));
            // Keep a place if it grants a VIP group OR credits.
            if ($g === '' && $cr <= 0) {
                continue;
            }
            $prizes[$pos] = [
                'group'   => $g,
                'days'    => max(0, (int) ($days[$pos] ?? 0)),
                'credits' => $cr,
            ];
        }
        return $prizes;
    }

    public function store(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        if (!ContestModel::available()) {
            $this->flash('error', 'Contest tables are missing. Run the update/migration first.');
            $this->redirect('/contests');
        }

        $title = trim((string) $this->input('title', ''));
        $max   = max(1, (int) $this->input('max_winners', 1));
        $mode  = (string) $this->input('mode', 'manual');
        $metric = (string) $this->input('metric', 'rank');
        $playtimeSource = (string) $this->input('playtime_source', 'all');
        $reset = $this->input('reset_stats') ? true : false;
        $resetAtStart = $this->input('reset_at_start') ? true : false;
        $extendExpiring = $this->input('extend_expiring') ? true : false;
        $endsRaw = $this->combineDateTime((string) $this->input('ends_at_date', ''), (string) $this->input('ends_at_time', ''));
        $startRaw = $this->combineDateTime((string) $this->input('start_at_date', ''), (string) $this->input('start_at_time', ''));
        $prizes = $this->readPrizes();

        // The default prize is the prize configured for position 1 (so there is
        // always a fallback for places without an explicit prize).
        $group   = trim((string) ($prizes[1]['group'] ?? $this->input('vip_group', '')));
        $days    = (int) ($prizes[1]['days'] ?? $this->input('vip_days', 0));
        $credits = (int) ($prizes[1]['credits'] ?? $this->input('vip_credits', 0));

        // A contest must grant SOMETHING for 1st place: a VIP group or credits.
        if ($title === '' || ($group === '' && $credits <= 0)) {
            $this->flash('error', 'Title and at least the 1st-place prize (VIP or credits) are required.');
            $this->redirect('/contests');
        }
        $endsTs = $endsRaw !== '' ? strtotime($endsRaw) : false;
        if ($endsTs === false) {
            $this->flash('error', 'Please choose a valid end date and time.');
            $this->redirect('/contests');
        }
        $endsAt = date('Y-m-d H:i:s', $endsTs);

        // Optional scheduled start.
        $startTs = $startRaw !== '' ? strtotime($startRaw) : false;
        $startAt = $startTs !== false ? date('Y-m-d H:i:s', $startTs) : '';
        if ($startTs !== false && $endsTs <= $startTs) {
            $this->flash('error', 'End time must be after the start time.');
            $this->redirect('/contests');
        }

        $id = ContestModel::create([
            'title'          => $title,
            'vip_group'      => $group,
            'vip_days'       => $days,
            'credit_amount'  => $credits,
            'prizes'         => $prizes,
            'metric'         => $metric,
            'playtime_source' => $playtimeSource,
            'max_winners'    => $max,
            'mode'           => $mode,
            'reset_stats'    => $reset,
            'reset_at_start' => $resetAtStart,
            'extend_expiring' => $extendExpiring,
            'start_at'       => $startAt,
            'ends_at'        => $endsAt,
        ], Auth::steamId());

        if ($id === 0) {
            $this->flash('error', 'Could not create the contest.');
            $this->redirect('/contests');
        }

        Logger::log('contest.create', $title, $mode . ', ' . $max . ' winner(s)');

        $scheduledInFuture = $startTs !== false && $startTs > time();
        if ($scheduledInFuture) {
            // Not started yet: announce the upcoming contest (starts at ...).
            $this->announce($id, 'scheduled', []);
            $this->flash('success', 'Contest scheduled. An announcement was sent to Discord.');
        } else {
            // Starts now: optionally reset stats and announce the start. Winners
            // are NOT drawn here — they're drawn only at the end (finalize), so
            // the result reflects the whole contest period, not the first moment.
            $contest = ContestModel::find($id);
            $resetFailed = false;
            if (!empty($contest['reset_at_start'])) {
                $resetFailed = !$this->resetStatsSafely('contest.reset_stats_start', (string) $title, (string) ($contest['metric'] ?? 'rank'));
            }
            $this->announce($id, 'started', []);
            // If the end time is already in the past, finalize immediately.
            if ($endsTs <= time()) {
                $this->finalize($id);
            }
            if ($resetFailed) {
                $this->flash('error', Lang::t('contests.reset_failed'));
            }
            $this->flash('success', 'Contest created and started. Winners will be drawn when it ends.');
        }
        $this->redirect('/contests');
    }

    /**
     * Manual "Grant all" retry for every not-yet-granted winner of a contest
     * (e.g. after restoring a backup). Same upsert-based fix as the automatic
     * path, just re-run on demand for the whole contest at once.
     */
    public function grantAll(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $cid = (int) $id;
        if (ContestModel::find($cid) === null) {
            $this->flash('error', 'Contest not found.');
            $this->redirect('/contests');
        }
        try {
            $granted = ContestModel::grantVipToWinners($cid);
            $this->flash('success', Lang::t('contests.grants_applied', ['n' => $granted]));
        } catch (\Throwable $e) {
            error_log('[CS2Panel] grant-all failed for contest #' . $cid . ': ' . $e->getMessage());
            $this->flash('error', Lang::t('contests.grant_all_failed'));
        }
        $this->redirect('/contests/' . $cid);
    }

    /**
     * Manual "Grant" retry for one winner row (e.g. after restoring a backup,
     * or when the automatic grant silently skipped them — same upsert-based
     * fix as the automatic path, just scoped to a single winner).
     */
    public function grantWinner(string $id, string $winnerId): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $cid = (int) $id;
        if (ContestModel::find($cid) === null) {
            $this->flash('error', 'Contest not found.');
            $this->redirect('/contests');
        }
        try {
            $granted = ContestModel::grantVipToWinner((int) $winnerId);
            $this->flash($granted ? 'success' : 'error', Lang::t($granted ? 'contests.grant_applied' : 'contests.grant_failed'));
        } catch (\Throwable $e) {
            error_log('[CS2Panel] manual winner grant failed for #' . $winnerId . ': ' . $e->getMessage());
            $this->flash('error', Lang::t('contests.grant_failed'));
        }
        $this->redirect('/contests/' . $cid);
    }

    /** Manually process a contest now ("draw & award" button). */
    public function process(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $cid = (int) $id;
        $contest = ContestModel::find($cid);
        if ($contest === null) {
            $this->flash('error', 'Contest not found.');
            $this->redirect('/contests');
        }
        try {
            $this->finalize($cid);
            $this->flash('success', 'Contest processed.');
        } catch (\Throwable $e) {
            error_log('[CS2Panel] manual contest process failed for #' . $cid . ': ' . $e->getMessage());
            $this->flash('error', 'Contest could not be fully processed (see the error log). Winners drawn so far were kept.');
        }
        $this->redirect('/contests');
    }

    /** Re-draw the current leaders for an active contest (refresh + re-announce). */
    public function redraw(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $cid = (int) $id;
        $contest = ContestModel::find($cid);
        if ($contest === null || $contest['status'] !== 'active') {
            $this->flash('error', 'Contest not found or already finished.');
            $this->redirect('/contests');
        }
        $winners = ContestModel::drawWinners($cid);
        $this->announce($cid, 'started', $winners);
        Logger::log('contest.redraw', $contest['title']);
        $this->flash('success', 'Leaders re-drawn and announced.');
        $this->redirect('/contests');
    }

    /** Edit a contest's settings (managers). Re-draws so prize changes apply. */
    public function update(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $cid = (int) $id;
        $contest = ContestModel::find($cid);
        if ($contest === null) {
            $this->flash('error', 'Contest not found.');
            $this->redirect('/contests');
        }

        $title = trim((string) $this->input('title', (string) $contest['title']));
        $max   = max(1, (int) $this->input('max_winners', (int) $contest['max_winners']));
        $mode  = (string) $this->input('mode', (string) $contest['mode']);
        $metric = (string) $this->input('metric', (string) ($contest['metric'] ?? 'rank'));
        $playtimeSource = (string) $this->input('playtime_source', (string) ($contest['playtime_source'] ?? 'all'));
        $reset = $this->input('reset_stats') ? true : false;
        $resetAtStart = $this->input('reset_at_start') ? true : false;
        $extendExpiring = $this->input('extend_expiring') ? true : false;
        $endsRaw = $this->combineDateTime((string) $this->input('ends_at_date', ''), (string) $this->input('ends_at_time', ''));
        $startRaw = $this->combineDateTime((string) $this->input('start_at_date', ''), (string) $this->input('start_at_time', ''));
        $prizes = $this->readPrizes();
        $group   = trim((string) ($prizes[1]['group'] ?? $this->input('vip_group', (string) $contest['vip_group'])));
        $days    = (int) ($prizes[1]['days'] ?? $this->input('vip_days', (int) $contest['vip_days']));
        $credits = (int) ($prizes[1]['credits'] ?? $this->input('vip_credits', (int) ($contest['credit_amount'] ?? 0)));

        $endsTs = $endsRaw !== '' ? strtotime($endsRaw) : strtotime((string) $contest['ends_at']);
        if ($endsTs === false) {
            $this->flash('error', 'Please choose a valid end date and time.');
            $this->redirect('/contests/' . $cid);
        }
        $startTs = $startRaw !== '' ? strtotime($startRaw) : (!empty($contest['start_at']) ? strtotime((string) $contest['start_at']) : false);
        $startAt = $startTs !== false ? date('Y-m-d H:i:s', $startTs) : '';

        ContestModel::update($cid, [
            'title'          => $title,
            'vip_group'      => $group,
            'vip_days'       => $days,
            'credit_amount'  => $credits,
            'prizes'         => $prizes,
            'metric'         => $metric,
            'playtime_source' => $playtimeSource,
            'max_winners'    => $max,
            'mode'           => $mode,
            'reset_stats'    => $reset,
            'reset_at_start' => $resetAtStart,
            'extend_expiring' => $extendExpiring,
            'start_at'       => $startAt,
            'ends_at'        => date('Y-m-d H:i:s', $endsTs),
        ]);

        // Do NOT draw winners on edit: an active contest must only be drawn at
        // the end (finalize) so results reflect the whole period. The "Current
        // standings" shown while active are a read-only preview (previewLeaders),
        // not stored rows. Updated prizes/winner-count apply automatically at
        // finalize; use the explicit "Re-draw" action to refresh a snapshot.
        Logger::log('contest.update', $title);
        $this->flash('success', 'Contest updated.');
        $this->redirect('/contests/' . $cid);
    }

    /**
     * Re-send the Discord announcement for a contest (manual re-post). Picks the
     * message that matches the contest's current state:
     *   scheduled -> "upcoming", active -> "started"/"live", finished -> result.
     */
    public function resend(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $cid = (int) $id;
        $contest = ContestModel::find($cid);
        if ($contest === null) {
            $this->flash('error', 'Contest not found.');
            $this->redirect('/contests');
        }
        if (!Discord::contestEnabled()) {
            $this->flash('error', 'No contest Discord webhook is configured.');
            $this->redirect('/contests/' . $cid);
        }

        $status = (string) ($contest['status'] ?? 'active');
        if ($status === 'scheduled') {
            $phase = 'scheduled';
        } elseif ($status === 'finished') {
            $phase = (string) $contest['mode'] === 'auto' ? 'awarded' : 'finished';
        } else {
            $phase = 'started';
        }
        // Re-sending only re-posts the message. For an ongoing contest the
        // standings are previewed read-only — drawing here would overwrite the
        // stored winners and announce them as final.
        $winners = match ($status) {
            'scheduled' => [],
            'active'    => ContestModel::previewLeaders($cid),
            default     => ContestModel::winners($cid),
        };

        $this->announce($cid, $phase, $winners);
        Logger::log('contest.resend', $contest['title']);
        $this->flash('success', 'Announcement re-sent to Discord.');
        $this->redirect('/contests/' . $cid);
    }

    public function delete(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        $cid = (int) $id;
        $contest = ContestModel::find($cid);
        ContestModel::delete($cid);

        // Announce the deletion on the contest webhook (no @everyone ping — a
        // deletion is admin housekeeping, not something to mass-notify).
        if ($contest !== null && Discord::contestEnabled()) {
            Discord::sendContest(
                Lang::t('discord.contest_deleted_title', [$contest['title']]),
                Lang::t('discord.contest_deleted_desc'),
                [],
                ['discord.contest_prize' => (string) $contest['vip_group']],
                false
            );
        }

        Logger::log('contest.delete', $contest['title'] ?? (string) $cid);
        $this->flash('success', 'Contest deleted.');
        $this->redirect('/contests');
    }

    /**
     * General maintenance cron for (near) real-time processing without opening
     * the panel. Runs contest start/finalize + history purge, expired admin-perk
     * revocation, and stale (unpaid) order cancellation. Call it once a minute:
     *   * * * * * curl -s "https://SITE/cron?token=SECRET" >/dev/null
     * The token is the 'cron_token' setting. No login is required (this runs
     * from cron), so the token is the only guard.
     * Returns JSON so cron/logs can see what happened.
     */
    public function cron(): void
    {
        $expected = trim((string) Settings::get('cron_token', ''));
        $given = trim((string) ($this->query('token', '') ?: $this->input('token', '')));

        if ($expected === '' || !hash_equals($expected, $given)) {
            $this->json(['ok' => false, 'error' => 'Invalid or missing token.'], 403);
        }

        try {
            // All periodic work lives in one place (see Maintenance::run),
            // shared by this cron endpoint and the throttled lazy page-load tick.
            $r = \App\Core\Maintenance::run();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] cron failed: ' . $e->getMessage());
            $this->json(['ok' => false, 'error' => 'Processing error (logged).'], 500);
        }

        $this->json(['ok' => true] + $r + [
            'at' => date('c'),
        ]);
    }

    /**
     * Start due scheduled contests and finalize ended ones, then purge old
     * history. Public entry point used by Maintenance::run() (so both the cron
     * and the lazy tick share the exact same contest logic). Safe to call when
     * contest tables don't exist yet — returns zero counters.
     *
     * @return array{started:int,processed:int,purged:int}
     */
    public static function runDueProcessing(): array
    {
        $started = 0;
        $processed = 0;
        $purged = 0;
        if (!ContestModel::available()) {
            return ['started' => 0, 'processed' => 0, 'purged' => 0];
        }
        $self = new self();
        foreach (ContestModel::dueToStartIds() as $cid) {
            try {
                $self->startContest($cid);
                $started++;
            } catch (\Throwable $e) {
                error_log('[CS2Panel] contest auto-start failed for #' . $cid . ': ' . $e->getMessage());
            }
        }
        foreach (ContestModel::dueContestIds() as $cid) {
            try {
                $self->finalize($cid);
                $processed++;
            } catch (\Throwable $e) {
                error_log('[CS2Panel] contest auto-finalize failed for #' . $cid . ': ' . $e->getMessage());
            }
        }
        $purged = ContestModel::purgeOldHistory(ContestModel::retentionDays());
        return ['started' => $started, 'processed' => $processed, 'purged' => $purged];
    }

                                                                        
    // Internal
                                                                        

    /**
     * Start a scheduled contest that has reached its start time: optionally
     * reset all stats, mark it active, and announce it's live.
     *
     * NOTE: winners are intentionally NOT drawn here. Drawing at start would
     * populate the winners table with a "current leaders" snapshot that looks
     * like a final result from the start date. Winners are drawn only when the
     * contest ends, in finalize(), so the results reflect the whole period.
     */
    private function startContest(int $cid): void
    {
        $contest = ContestModel::find($cid);
        if ($contest === null || ($contest['status'] ?? '') !== 'scheduled') {
            return;
        }
        if (!empty($contest['reset_at_start'])) {
            $this->resetStatsSafely('contest.reset_stats_start', (string) $contest['title'], (string) ($contest['metric'] ?? 'rank'));
        }
        ContestModel::markStarted($cid);
        try {
            $this->announce($cid, 'live', []);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] contest announce (start) failed: ' . $e->getMessage());
        }
        Logger::log('contest.start', $contest['title']);
    }

    /**
     * Back up the database, then reset only what the contest ranks by: rank
     * points for rank contests, play time for playtime contests. Other data is
     * left untouched, and nothing is reset when the backup fails. Never throws.
     */
    private function resetStatsSafely(string $logAction, string $title, string $metric): bool
    {
        @ignore_user_abort(true);
        @set_time_limit(0);
        $backup = null;
        try {
            $backup = \App\Core\Backup::full();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] backup before contest reset failed: ' . $e->getMessage());
        }
        if ($backup === null) {
            error_log('[CS2Panel] contest reset skipped for "' . $title . '": the database backup failed.');
            return false;
        }
        try {
            if ($metric === 'playtime') {
                StatsBackend::resetAllPlaytime();
            } else {
                StatsBackend::resetAllRanks();
            }
            Logger::log($logAction, $title, 'backup: ' . basename($backup));
            return true;
        } catch (\Throwable $e) {
            error_log('[CS2Panel] contest stats reset failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Finalize a contest: draw the final winners, grant VIP (auto mode only),
     * optionally reset all stats, mark finished, and announce the result.
     */
    private function finalize(int $cid): void
    {
        $contest = ContestModel::find($cid);
        if ($contest === null || $contest['status'] !== 'active') {
            return;
        }

        $winners = ContestModel::drawWinners($cid);

        $statsResetDone = false;
        if ((string) $contest['mode'] === 'auto') {
            $granted = ContestModel::grantVipToWinners($cid);
            Logger::log('contest.award', $contest['title'], $granted . ' winner(s) received at least one prize component');
        } else {
            Logger::log('contest.finish', $contest['title'], 'manual — VIP added by admin');
        }

        // Optional destructive follow-up after the draw.
        if (!empty($contest['reset_stats'])) {
            $statsResetDone = $this->resetStatsSafely('contest.reset_stats', (string) $contest['title'], (string) ($contest['metric'] ?? 'rank'));
        }

        ContestModel::markFinished($cid, $statsResetDone);
        try {
            $this->announce($cid, (string) $contest['mode'] === 'auto' ? 'awarded' : 'finished', $winners);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] contest announce (finalize) failed: ' . $e->getMessage());
        }
    }

    /**
     * Send a contest announcement to the separate contest webhook (@everyone).
     * $phase: 'started' | 'awarded' | 'finished'.
     * @param array<int,array<string,mixed>> $winners winner rows from the model
     */
    private function announce(int $cid, string $phase, array $winners): void
    {
        if (!Discord::contestEnabled()) {
            return;
        }
        $contest = ContestModel::find($cid);
        if ($contest === null) {
            return;
        }

        $title = match ($phase) {
            'scheduled' => Lang::t('discord.contest_scheduled_title', [$contest['title']]),
            'live'      => Lang::t('discord.contest_live_title', [$contest['title']]),
            'awarded'   => Lang::t('discord.contest_awarded_title', [$contest['title']]),
            'finished'  => Lang::t('discord.contest_finished_title', [$contest['title']]),
            default     => Lang::t('discord.contest_started_title', [$contest['title']]),
        };
        $subtitle = match ($phase) {
            'scheduled' => Lang::t('discord.contest_scheduled_desc'),
            'live'      => Lang::t('discord.contest_live_desc'),
            'awarded'   => Lang::t('discord.contest_awarded_details'),
            'finished'  => Lang::t('discord.contest_finished_desc'),
            default     => Lang::t('discord.contest_started_desc'),
        };

                                                                                 
        // Include every prize slot, not only the first winner.
        $maxWinners = max(1, (int) $contest['max_winners']);
        $prizeLines = [];
        for ($place = 1; $place <= $maxWinners; $place++) {
            $prize = ContestModel::prizeFor($contest, $place);
            $prizeLines[] = Lang::t('discord.contest_place', [$place]) . ': ' . ContestModel::prizeLabel($prize);
        }

        $meta = [
            'discord.contest_prizes' => implode("\n", $prizeLines),
            // How long the CONTEST itself runs (not the VIP reward duration).
            'discord.duration'       => $this->contestDuration($contest),
        ];
        // Start and end go into a single full-width field so Discord renders
        // them one under the other instead of side by side.
        $period = [];
        if (!empty($contest['start_at'])) {
            $period[] = Lang::t('discord.contest_starts') . ': ' . date('d.m.Y H:i', (int) strtotime((string) $contest['start_at']));
        }
        $period[] = Lang::t('discord.contest_ends') . ': ' . date('d.m.Y H:i', (int) strtotime((string) $contest['ends_at']));
        $meta['discord.contest_period'] = implode("\n", $period);

        // Stored winner snapshots take precedence over the current configuration.
        $metric = (string) ($contest['metric'] ?? 'rank');
        $list = [];
        foreach ($winners as $w) {
            $prize = ContestModel::winnerPrize($contest, $w);
            $winner = [
                'position' => (int) ($w['position'] ?? 0),
                'name'     => $w['name'] ?? null,
                'steamid'  => (string) ($w['steamid'] ?? ''),
                'points'   => (int) ($w['points'] ?? 0),
                // A playtime contest ranks by seconds played, not by points.
                'metric'   => $metric,
                'prize'    => ContestModel::prizeLabel($prize),
            ];
            if ($prize['group'] !== '') $winner['vip_granted'] = !empty($w['vip_granted']);
            if ($prize['credits'] > 0) $winner['credits_granted'] = !empty($w['credits_granted']);
            $list[] = $winner;
        }

        // Before the contest ends these are only the current standings, so the
        // embed must not call them winners.
        $provisional = in_array($phase, ['started', 'live'], true);
        Discord::sendContest($title, $subtitle, $list, $meta, true, $provisional);
    }

    /** How long the contest runs, from its start (or creation) to its end. */
    private function contestDuration(array $contest): string
    {
        $start = strtotime((string) ($contest['start_at'] ?: $contest['started'] ?: ''));
        $end   = strtotime((string) $contest['ends_at']);
        if (!$start || !$end || $end <= $start) {
            return '';
        }
        $days  = (int) floor(($end - $start) / 86400);
        $hours = (int) floor((($end - $start) % 86400) / 3600);
        $parts = [];
        if ($days > 0) {
            $parts[] = Lang::t('discord.days_n', [$days]);
        }
        if ($hours > 0) {
            $parts[] = Lang::t('time.ago', ['n' => $hours, 'unit' => Lang::t($hours > 1 ? 'time.hours' : 'time.hour')]);
        }
        return $parts ? implode(' ', $parts) : Lang::t('discord.days_n', [0]);
    }
}

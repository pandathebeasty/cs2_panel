<?php use App\Core\Lang; use App\Core\Settings; use App\Core\View;
$logo = Settings::get('logo_path', '') ?: '/assets/img/logo.svg';
$siteName = Settings::get('site_name', 'CS2 Panel');
$heroServers = $heroServers ?? [];
$firstServer = $heroServers[0] ?? null;
$serverOverviewUnavailable = $serverOverviewUnavailable ?? false;
?>
<div class="auth-experience portal-auth">
    <section class="auth-scene auth-live-scene panel-live-hero" data-dashboard-hero
             data-online-label="<?= View::e(Lang::t('servers.online')) ?>"
             data-loading-label="<?= View::e(Lang::t('portal.server_loading')) ?>"
             data-error-label="<?= View::e(Lang::t('portal.server_unavailable')) ?>"
             aria-label="<?= View::e(Lang::t('nav.servers')) ?>">
        <div class="panel-server-viewport" data-server-viewport tabindex="0" role="region" aria-label="<?= View::e(Lang::t('portal.server_carousel')) ?>">
            <?php foreach ($heroServers ?: [null] as $index => $server): ?>
            <div class="panel-server-slide" data-server-slide
                 data-info-url="<?= View::e($server['info_url'] ?? '') ?>"
                 data-connect-url="<?= View::e($server['connect_url'] ?? '') ?>"
                 data-server-name="<?= View::e($server['hostname'] ?? $siteName) ?>"
                 data-server-address="<?= View::e($server['address'] ?? '') ?>"
                 aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
                <img class="panel-server-map" data-server-map alt="" hidden>
                <div class="panel-server-terrain" aria-hidden="true"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="auth-scene-brand">
            <img src="<?= View::e($logo) ?>" alt="">
            <span><?= View::e(Lang::t('portal.community')) ?></span>
        </div>
        <div class="auth-live-copy">
            <span class="panel-live-status" data-server-status role="status"><?= View::e(Lang::t($serverOverviewUnavailable ? 'portal.server_unavailable' : ($firstServer ? 'portal.server_loading' : 'servers.none'))) ?></span>
            <h1 data-current-server><?= View::e($firstServer['hostname'] ?? $siteName) ?></h1>
            <dl class="auth-live-details">
                <div><dt><?= View::e(Lang::t('servers.map')) ?></dt><dd data-current-map>&mdash;</dd></div>
                <div><dt><?= View::e(Lang::t('servers.players')) ?></dt><dd data-current-players>&mdash;</dd></div>
                <div class="auth-live-address"><dt><?= View::e(Lang::t('servers.address')) ?></dt><dd data-current-address><?= View::e($firstServer['address'] ?? '—') ?></dd></div>
            </dl>
            <a class="btn btn-primary portal-cta" data-server-connect
               <?= !empty($firstServer['connect_url']) ? 'href="' . View::e($firstServer['connect_url']) . '"' : 'aria-disabled="true" tabindex="-1"' ?>>
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M7 4v16l14-8z"/></svg><?= View::e(Lang::t('servers.connect')) ?>
            </a>
        </div>
        <?php if (count($heroServers) > 1): ?>
        <div class="panel-server-dots" role="group" aria-label="<?= View::e(Lang::t('nav.servers')) ?>">
            <?php foreach ($heroServers as $index => $server): ?>
            <button type="button" data-server-dot="<?= $index ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"
                    aria-label="<?= View::e(Lang::t('portal.server_slide', ['n' => $index + 1, 'name' => $server['hostname']])) ?>" title="<?= View::e($server['hostname']) ?>"></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
<div class="auth-card auth-login-card">
    <img class="logo" src="<?= View::e($logo) ?>" alt="logo">
    <div class="auth-eyebrow">Counter-Strike 2</div>
    <h2><?= View::e($siteName) ?></h2>
    <p class="portal-auth-description"><?= View::e(Lang::t('portal.login_description')) ?></p>
    <a class="btn-steam" href="/auth/steam">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.5 2 2 6.5 2 12l5.4 2.2a2.8 2.8 0 011.6-.5l2.4-3.5v-.1a3.7 3.7 0 113.7 3.7h-.1l-3.4 2.5a2.8 2.8 0 01-5.5.7L2 15.4A10 10 0 1012 2zm-3.9 15.2l-1.2-.5a2.1 2.1 0 003.9-.3 2.1 2.1 0 10-2.7-2.7l1.3.5a1.5 1.5 0 11-1.3 2.7zM18 9.5a2.5 2.5 0 10-5 0 2.5 2.5 0 005 0zm-4.2 0a1.7 1.7 0 113.4 0 1.7 1.7 0 01-3.4 0z"/></svg>
        <?= View::e(Lang::t('auth.login_steam')) ?>
    </a>
    <a class="btn-guest" href="/auth/guest">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?= View::e(Lang::t('auth.continue_guest')) ?>
    </a>
    <div class="auth-credit">
        <?= \App\Core\Brand::footer() ?>
    </div>
</div>
    <script defer src="/assets/js/dashboard-hero.js?v=<?= View::e(\App\Core\Version::current() . '.' . filemtime(PUBLIC_PATH . '/assets/js/dashboard-hero.js')) ?>"></script>
</div>

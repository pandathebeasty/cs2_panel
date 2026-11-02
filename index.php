<?php
/**
 * CS2 Panel - front controller.
 *
 * Works in two layouts:
 *  A) DocumentRoot = public/  (recommended)         -> app/ is one level up
 *  B) everything in one folder (e.g. host.ro/test)  -> app/ sits next to index.php
 * and under a subfolder (e.g. /test) as well as at the domain root.
 */

declare(strict_types=1);

                                                         
if (is_dir(__DIR__ . '/app')) {
    define('BASE_PATH', __DIR__);          // layout B: app/ next to index.php
} else {
    define('BASE_PATH', dirname(__DIR__)); // layout A: app/ one level up
}

// Web root (where index.php + assets/ physically live). In layout A this is
// BASE_PATH/public; in layout B it is the same folder as index.php.
define('PUBLIC_PATH', __DIR__);

                                                                             
// SCRIPT_NAME is like "/test/index.php" -> base = "/test"
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');
define('BASE_URL', $basePath);            // used to build links/redirects

                                                            
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
}
if ($path === '' || $path[0] !== '/') {
    $path = '/' . $path;
}

/*
 * PHP built-in server (php -S): serve real static files directly so CSS/JS/img
 * work in development. On Apache/Nginx this branch never runs.
 */
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . $path;
    if (str_starts_with($path, '/assets/') && is_file($file)) {
        return false;
    }
}

require BASE_PATH . '/app/bootstrap.php';

use App\Core\DevMode;
use App\Core\Router;

// Not installed yet: force the wizard, or allow dev preview.
if (!CS2PANEL_INSTALLED) {
    if (DevMode::enabled() && !str_starts_with($path, '/install')) {
        DevMode::seedConfig();
        DevMode::fakeLogin();
    } elseif (!str_starts_with($path, '/install')) {
        header('Location: ' . (BASE_URL === '' ? '/install' : BASE_URL . '/install'));
        exit;
    }
}

if (CS2PANEL_INSTALLED) {
    \App\Core\Schema::sync();
}

$router = require BASE_PATH . '/app/routes.php';

/** @var Router $router */
$router->dispatch($_SERVER['REQUEST_METHOD'], $path);

// Periodic database maintenance (start/finish contests, expire perks, cancel
// stale orders). It's throttled to run at most once per minute and is best-effort.
// On PHP-FPM we flush the response to the visitor FIRST so they never wait for it;
// on other SAPIs (Apache mod_php, CGI, LiteSpeed) fastcgi_finish_request doesn't
// exist, so we run it inline — otherwise scheduled contests would never go live
// on hosts without a cron. The throttle keeps the cost negligible.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $path !== '/cron') {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        ignore_user_abort(true);
    }
    \App\Core\Maintenance::lazyTick();
}

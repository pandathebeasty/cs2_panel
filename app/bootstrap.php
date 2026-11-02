<?php

declare(strict_types=1);

   
                                                                     
   

                                          
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

                                                                 
if (!function_exists('url')) {
                                                                         
    function url(string $path = '/'): string
    {
        return App\Core\Url::to($path);
    }
}
if (!function_exists('e')) {
    /** HTML-escape shortcut for views. */
    function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

                 
$configFile = BASE_PATH . '/config/config.php';
if (is_file($configFile)) {
    App\Core\Config::init(require $configFile);
    define('CS2PANEL_INSTALLED', true);
} else {
    // Not installed yet: boot with empty config, the router will redirect to /install
    App\Core\Config::init(['app' => ['timezone' => 'UTC', 'debug' => false, 'per_page' => 25]]);
    define('CS2PANEL_INSTALLED', false);
}

                                                                
// Settings that also have a config.php counterpart (Steam realm/return_url,
// stats backend + table names, timezone, per-page, ...) are
// read from config BEFORE the database in some paths. If config.php drifted from
// what the admin set in Settings (e.g. after moving the panel to another
// subfolder, or editing settings directly), auto-heal it here on load. The sync
// rewrites config.php ONLY when a value actually differs, so a normal request
// never touches disk.
if (defined('CS2PANEL_INSTALLED') && CS2PANEL_INSTALLED === true) {
    try {
        App\Core\Settings::migrateThemeMode();
        App\Core\Config::syncFromSettings();
    } catch (\Throwable $e) {
        // Non-fatal: login still works via the runtime website_url fallback.
        error_log('[CS2Panel] config.php auto-sync skipped: ' . $e->getMessage());
    }
}

                                                                                
$tz = (string) App\Core\Settings::get('timezone', App\Core\Config::get('app.timezone', 'UTC'));
if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
    $tz = (string) App\Core\Config::get('app.timezone', 'UTC') ?: 'UTC';
}
date_default_timezone_set($tz);

               
App\Core\Lang::boot();

                         
$debug = (bool) App\Core\Config::get('app.debug', false);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting($debug ? E_ALL : 0);

/**
 * Append one entry to the readable app error log (error.log), which the Logs
 * page ("Error Log" tab) reads. Kept best-effort so logging never itself fails.
 */
if (!function_exists('cs2panel_log_error')) {
    function cs2panel_log_error(string $message, string $trace = ''): void
    {
        $line = '[CS2Panel] ' . $message;
        error_log($line);
        $logFile = BASE_PATH . '/error.log';
        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n" . ($trace !== '' ? $trace . "\n" : '') . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

if (!function_exists('cs2panel_trace')) {
    function cs2panel_trace(string $stage): void
    {
        if (($_GET['diag'] ?? '') !== '1' && ($_POST['diag'] ?? '') !== '1') {
            return;
        }
        static $started = null;
        $started ??= (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        @file_put_contents(BASE_PATH . '/error.log', '[' . date('Y-m-d H:i:s') . '] TRACE +' . $elapsed . 'ms peak=' . memory_get_peak_usage(true) . ' ' . $stage . "\n", FILE_APPEND | LOCK_EX);
    }
}

/**
 * Send the browser to the Error Log tab when a 500 happens for a logged-in
 * manager, so the admin lands exactly where they can read what broke. Guests
 * (or when headers were already sent) get a plain 500 body instead.
 * Returns true when a redirect was issued.
 */
if (!function_exists('cs2panel_redirect_to_logs')) {
    function cs2panel_redirect_to_logs(): bool
    {
        if (headers_sent()) {
            return false;
        }
        // Only redirect real admin sessions; never bounce API/JSON or guests.
        $isJson = str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $canSee = false;
        try {
            $canSee = class_exists('App\\Core\\Auth') && App\Core\Auth::check() && App\Core\Auth::canManage();
        } catch (\Throwable $ignored) {
            $canSee = false;
        }
        if ($isJson || !$canSee) {
            return false;
        }
        $target = (defined('BASE_URL') && BASE_URL !== '' ? BASE_URL : '') . '/logs?err=1#errors';
        header('Location: ' . $target, true, 302);
        return true;
    }
}

set_exception_handler(function (\Throwable $e) use ($debug): void {
    http_response_code(500);
    cs2panel_log_error(
        $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
        $e->getTraceAsString()
    );
    if ($debug) {
        echo '<pre style="padding:20px;font-family:monospace;background:#1a1a2e;color:#ff6b6b;">';
        echo htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString());
        echo '</pre>';
        return;
    }
    if (cs2panel_redirect_to_logs()) {
        return;
    }
    echo '<h1>500</h1><p>An error occurred. Check the server logs.</p>';
});

/**
 * Catch FATAL errors (E_ERROR, out-of-memory, max_execution_time, parse/compile
 * errors) that bypass the exception handler entirely. Without this, a fatal —
 * e.g. a big DB backup exhausting memory during a reset — produces a blank/500
 * page with NOTHING recorded in the error log. Now it is logged and, for a
 * logged-in admin, redirects to the Error Log tab.
 */
register_shutdown_function(function () use ($debug): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    cs2panel_log_error(
        'FATAL ' . $err['message'] . ' @ ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?')
    );
    if ($debug) {
        echo '<pre style="padding:20px;font-family:monospace;background:#1a1a2e;color:#ff6b6b;">';
        echo htmlspecialchars('FATAL: ' . $err['message'] . "\n" . ($err['file'] ?? '') . ':' . ($err['line'] ?? ''));
        echo '</pre>';
        return;
    }
    if (cs2panel_redirect_to_logs()) {
        return;
    }
    echo '<h1>500</h1><p>A fatal error occurred. Check the Error Log.</p>';
});

                                     
// How long users stay logged in. Configurable in Settings ("login_days"):
//   > 0  -> that many days (sliding window, survives browser restarts)
//   = 0  -> effectively never expires (10-year cookie/session)
// Default is 30 days when unset.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $loginDays = App\Core\Settings::get('login_days', null);
    $loginDays = ($loginDays === null || $loginDays === '') ? 30 : max(0, (int) $loginDays);
    // 0 = never expire -> use a very long lifetime (10 years).
    $sessionLifetime = $loginDays > 0 ? ($loginDays * 86400) : (86400 * 3650);

    // Server-side data must live at least as long as the cookie.
    @ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    $secure = (($_SERVER['HTTPS'] ?? '') === 'on'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_start();

    // Refresh the cookie on each request so the window slides forward while the
    // user is active (otherwise it would expire N days after login regardless).
    if (!empty($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $sessionLifetime,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
    }
}

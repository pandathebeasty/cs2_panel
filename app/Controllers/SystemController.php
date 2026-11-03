<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Backup;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\Settings;
use App\Core\Updater;
use App\Core\Version;
use App\Models\PanelUserModel;
use App\Models\TwoFactorModel;

/**
 * Owner "System" center: health checks, scheduled backups, monitoring and
 * security settings, and rolling back the last update.
 */
final class SystemController extends Controller
{
    public function index(): void
    {
        Auth::requireRoot();
        $this->redirect('/settings#sys-health');
    }

    /** Data for the System tabs rendered inside Settings (root only). */
    public static function viewData(): array
    {
        $staff = [];
        try {
            foreach (PanelUserModel::all() as $u) {
                $sid = (string) ($u['steamid64'] ?? '');
                $staff[$sid] = ['steamid' => $sid, 'name' => (string) ($u['name'] ?? $sid), 'role' => (string) ($u['role'] ?? '')];
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] system staff list failed: ' . $e->getMessage());
        }
        foreach ((array) Config::get('super_admins', []) as $sid) {
            $staff[(string) $sid] ??= ['steamid' => (string) $sid, 'name' => (string) $sid, 'role' => 'owner'];
        }
        foreach ($staff as $sid => &$member) {
            $member['twofa'] = TwoFactorModel::enabled((string) $sid);
        }
        unset($member);

        return [
            'checks'   => self::checks(),
            'backups'  => Backup::files(),
            'rollback' => Updater::rollbackInfo(),
            'staff'    => array_values($staff),
            's'        => [
                'backup_auto_enabled'    => Settings::bool('backup_auto_enabled', false),
                'backup_auto_frequency'  => (string) Settings::get('backup_auto_frequency', 'daily'),
                'backup_auto_time'       => (string) Settings::get('backup_auto_time', '03:00'),
                'backup_auto_weekday'    => (int) Settings::get('backup_auto_weekday', '1'),
                'backup_auto_keep'       => (int) Settings::get('backup_auto_keep', '7'),
                'backup_max_age_days'    => (int) Settings::get('backup_max_age_days', '0'),
                'backup_auto_compress'   => Settings::bool('backup_auto_compress', true),
                'backup_auto_status'     => (string) Settings::get('backup_auto_status', ''),
                'monitor_enabled'        => Settings::bool('monitor_enabled', true),
                'monitor_interval'       => (int) Settings::get('monitor_interval', '2'),
                'monitor_down_after'     => (int) Settings::get('monitor_down_after', '3'),
                'monitor_retention_days' => (int) Settings::get('monitor_retention_days', '30'),
                'monitor_webhook'        => (string) Settings::get('monitor_webhook', ''),
                'security_require_2fa'   => Settings::bool('security_require_2fa', false),
                'audit_retention_days'   => (int) Settings::get('audit_retention_days', '0'),
            ],
        ];
    }

    public function saveBackups(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $freq = (string) $this->input('backup_auto_frequency', 'daily');
        $time = (string) $this->input('backup_auto_time', '');
        if ($this->input('backup_auto_minute') !== null) {
            $time = sprintf('%02d:%02d', max(0, min(23, (int) $this->input('backup_auto_hour', 3))), max(0, min(59, (int) $this->input('backup_auto_minute', 0))));
        }
        $wasEnabled = Settings::bool('backup_auto_enabled', false);
        $enabled = $this->input('backup_auto_enabled') ? '1' : '0';
        Settings::set('backup_auto_enabled', $enabled);
        Settings::set('backup_auto_frequency', in_array($freq, Backup::FREQUENCIES, true) ? $freq : 'daily');
        Settings::set('backup_auto_time', preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '03:00');
        Settings::set('backup_auto_weekday', (string) max(1, min(7, (int) $this->input('backup_auto_weekday', 1))));
        Settings::set('backup_auto_keep', (string) max(1, min(365, (int) $this->input('backup_auto_keep', 7))));
        $maxAge = $this->input('backup_expire_enabled') ? max(1, min(3650, (int) $this->input('backup_max_age_days', 30))) : 0;
        Settings::set('backup_max_age_days', (string) $maxAge);
        Settings::set('backup_auto_compress', $this->input('backup_auto_compress') ? '1' : '0');
        if ($enabled === '1' && !$wasEnabled) {
            // Start counting from now: the first backup runs at the next slot.
            Settings::set('backup_auto_last', '0');
        }
        Logger::log('system.backup_schedule', null, $enabled === '1' ? $freq . ' ' . $time : 'off');
        $this->flash('success', Lang::t('settings.saved'));
        $this->redirect('/settings#sys-backups');
    }

    public function backupNow(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        @set_time_limit(0);
        $file = Backup::full();
        if ($file !== null && Settings::bool('backup_auto_compress', true)) {
            $file = Backup::compress($file) ?? $file;
        }
        if ($file === null) {
            $this->flash('error', Lang::t('system.backup_failed'));
        } else {
            Logger::log('system.backup_create', basename($file));
            $this->flash('success', Lang::t('system.backup_created', ['file' => basename($file)]));
        }
        $this->redirect('/settings#sys-backups');
    }

    public function download(): void
    {
        Auth::requireRoot();
        $path = Backup::path((string) $this->query('file', ''));
        if ($path === null) {
            http_response_code(404);
            exit('Not found');
        }
        Logger::log('system.backup_download', basename($path));
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function deleteBackup(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $path = Backup::path((string) $this->input('file', ''));
        if ($path !== null && @unlink($path)) {
            Logger::log('system.backup_delete', basename($path));
            $this->flash('success', Lang::t('system.backup_deleted'));
        } else {
            $this->flash('error', Lang::t('system.backup_missing'));
        }
        $this->redirect('/settings#sys-backups');
    }

    public function restoreBackup(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $path = Backup::path((string) $this->input('file', ''));
        if ($path === null || !preg_match('/\.sql(\.gz)?$/', $path)) {
            $this->flash('error', Lang::t('system.backup_missing'));
            $this->redirect('/settings#sys-backups');
        }
        if (trim((string) $this->input('confirm', '')) !== 'RESTORE') {
            $this->flash('error', Lang::t('system.restore_confirm_wrong'));
            $this->redirect('/settings#sys-backups');
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
        // Safety net: snapshot the current database before overwriting it.
        $safety = Backup::full('backup_prerestore');
        if ($safety !== null) {
            Backup::compress($safety);
        }
        $result = Backup::restore($path);
        Logger::log('system.backup_restore', basename($path), $result['ok'] ? 'ok' : 'failed');
        $this->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? Lang::t('system.restore_ok', ['n' => $result['statements']])
            : Lang::t('system.restore_failed') . ' ' . $result['message']);
        $this->redirect('/settings#sys-backups');
    }

    public function saveMonitor(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $webhook = trim((string) $this->input('monitor_webhook', ''));
        if ($webhook !== '' && !preg_match('#^https://(?:[a-z0-9-]+\.)?discord(?:app)?\.com/api/webhooks/#i', $webhook)) {
            $this->flash('error', Lang::t('system.webhook_invalid'));
            $this->redirect('/settings#sys-monitor');
        }
        Settings::set('monitor_enabled', $this->input('monitor_enabled') ? '1' : '0');
        Settings::set('monitor_interval', (string) max(1, min(60, (int) $this->input('monitor_interval', 2))));
        Settings::set('monitor_down_after', (string) max(1, min(30, (int) $this->input('monitor_down_after', 3))));
        Settings::set('monitor_retention_days', (string) max(1, min(365, (int) $this->input('monitor_retention_days', 30))));
        Settings::set('monitor_webhook', $webhook);
        Logger::log('system.monitor_settings');
        $this->flash('success', Lang::t('settings.saved'));
        $this->redirect('/settings#sys-monitor');
    }

    public function saveSecurity(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        $require = $this->input('security_require_2fa') ? '1' : '0';
        if ($require === '1' && !TwoFactorModel::enabled((string) Auth::steamId())) {
            // Avoid locking the owner out of the settings they just changed.
            $this->flash('error', Lang::t('system.require_2fa_self'));
            $this->redirect('/settings#sys-security');
        }
        Settings::set('security_require_2fa', $require);
        Settings::set('audit_retention_days', (string) max(0, min(3650, (int) $this->input('audit_retention_days', 0))));
        Logger::log('system.security_settings', null, '2fa=' . $require);
        $this->flash('success', Lang::t('settings.saved'));
        $this->redirect('/settings#sys-security');
    }

    public function rollback(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        if (trim((string) $this->input('confirm', '')) !== 'ROLLBACK') {
            $this->flash('error', Lang::t('system.restore_confirm_wrong'));
            $this->redirect('/settings#updates');
        }
        $result = Updater::rollback((bool) $this->input('restore_db'));
        $this->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('/settings#updates');
    }

    /**
     * Environment checks with a status and a short recommendation.
     * @return array<int,array{group:string,label:string,status:string,value:string,hint:string}>
     */
    private static function checks(): array
    {
        $c = [];
        $add = static function (string $group, string $label, string $status, string $value, string $hint = '') use (&$c): void {
            $c[] = compact('group', 'label', 'status', 'value', 'hint');
        };

        $add('php', 'PHP', version_compare(PHP_VERSION, '8.2', '>=') ? 'ok' : (version_compare(PHP_VERSION, '8.1', '>=') ? 'warn' : 'fail'),
            PHP_VERSION, Lang::t('health.hint_php'));
        foreach (['pdo_mysql' => true, 'curl' => true, 'mbstring' => true, 'openssl' => true, 'zip' => true, 'zlib' => false, 'gd' => false, 'sockets' => false] as $ext => $required) {
            $loaded = extension_loaded($ext);
            $add('php', 'ext-' . $ext, $loaded ? 'ok' : ($required ? 'fail' : 'warn'),
                $loaded ? Lang::t('health.loaded') : Lang::t('health.missing'), $loaded ? '' : Lang::t('health.hint_ext'));
        }
        $memory = self::bytes((string) ini_get('memory_limit'));
        $add('php', 'memory_limit', $memory < 0 || $memory >= 256 * 1048576 ? 'ok' : ($memory >= 128 * 1048576 ? 'warn' : 'fail'),
            (string) ini_get('memory_limit'), Lang::t('health.hint_memory'));
        $opcache = function_exists('opcache_get_status') && (bool) @ini_get('opcache.enable');
        $add('php', 'OPcache', $opcache ? 'ok' : 'warn', $opcache ? Lang::t('health.on') : Lang::t('health.off'), Lang::t('health.hint_opcache'));
        $display = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL);
        $add('php', 'display_errors', $display ? 'warn' : 'ok', $display ? Lang::t('health.on') : Lang::t('health.off'), Lang::t('health.hint_display'));

        try {
            $pdo = Database::connection();
            $server = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $add('db', Database::isMariaDb() ? 'MariaDB' : 'MySQL', 'ok', $server);
            $size = (float) Database::scalar('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
            $add('db', Lang::t('health.db_size'), 'ok', self::human((int) $size));
            $applied = Database::tableExists('panel_migrations')
                ? array_column(Database::all('SELECT filename FROM panel_migrations'), 'filename') : [];
            $pending = array_diff(array_map('basename', glob(Migrator::dir() . '/*.sql') ?: []), $applied);
            $add('db', Lang::t('health.migrations'), $pending ? 'warn' : 'ok',
                count($applied) . ' / ' . (count($applied) + count($pending)), $pending ? Lang::t('health.hint_migrations') : '');
        } catch (\Throwable $e) {
            $add('db', 'Database', 'fail', mb_substr($e->getMessage(), 0, 120));
        }

        foreach (['db_backups' => BASE_PATH . '/db_backups', 'uploads' => PUBLIC_PATH . '/assets/img/uploads', 'app/Data' => BASE_PATH . '/app/Data', 'root' => BASE_PATH] as $label => $dir) {
            $ok = is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
            $add('files', Lang::t('health.writable') . ' ' . $label, $ok ? 'ok' : ($label === 'root' ? 'warn' : 'fail'),
                $ok ? Lang::t('health.yes') : Lang::t('health.no'), $ok ? '' : Lang::t('health.hint_writable'));
        }
        $free = @disk_free_space(BASE_PATH);
        if ($free !== false) {
            $add('files', Lang::t('health.disk_free'), $free > 1073741824 ? 'ok' : ($free > 268435456 ? 'warn' : 'fail'), self::human((int) $free), Lang::t('health.hint_disk'));
        }
        $log = BASE_PATH . '/error.log';
        $logSize = is_file($log) ? (int) filesize($log) : 0;
        $add('files', 'error.log', $logSize > 20 * 1048576 ? 'warn' : 'ok', self::human($logSize), $logSize > 20 * 1048576 ? Lang::t('health.hint_log') : '');

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $add('security', 'HTTPS', $https ? 'ok' : 'warn', $https ? Lang::t('health.yes') : Lang::t('health.no'), $https ? '' : Lang::t('health.hint_https'));
        $add('security', Lang::t('health.steam_key'), \App\Core\Steam::apiKey() !== '' ? 'ok' : 'warn',
            \App\Core\Steam::apiKey() !== '' ? Lang::t('health.yes') : Lang::t('health.no'), Lang::t('health.hint_steam_key'));
        $add('security', '2FA', Settings::bool('security_require_2fa', false) ? 'ok' : 'warn',
            Settings::bool('security_require_2fa', false) ? Lang::t('health.on') : Lang::t('health.off'), Lang::t('health.hint_2fa'));

        $now = time();
        $cron = (int) Settings::get('last_cron_run', '0');
        $add('jobs', 'Cron', $cron > 0 && $now - $cron < 900 ? 'ok' : 'warn',
            $cron > 0 ? date('Y-m-d H:i', $cron) : Lang::t('health.never'), Lang::t('health.hint_cron'));
        $maint = (int) Settings::get('last_maintenance_run', '0');
        $add('jobs', Lang::t('health.maintenance'), $maint > 0 && $now - $maint < 86400 ? 'ok' : 'warn',
            $maint > 0 ? date('Y-m-d H:i', $maint) : Lang::t('health.never'));
        $status = explode('|', (string) Settings::get('backup_auto_status', ''));
        if (!Settings::bool('backup_auto_enabled', false)) {
            $add('jobs', Lang::t('health.auto_backup'), 'warn', Lang::t('health.off'), Lang::t('health.hint_backup'));
        } else {
            $add('jobs', Lang::t('health.auto_backup'), ($status[0] ?? '') === 'error' ? 'fail' : 'ok',
                isset($status[1]) && (int) $status[1] > 0 ? ($status[0] . ' · ' . date('Y-m-d H:i', (int) $status[1])) : Lang::t('health.never'));
        }
        $add('jobs', Lang::t('health.version'), 'ok', Version::current() . (Updater::cachedHasUpdate() ? ' → ' . (Updater::latestCached()['version'] ?? '') : ''));
        return $c;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return round($v, $i > 1 ? 1 : 0) . ' ' . $units[$i];
    }
}

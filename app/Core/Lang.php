<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Simple translation (i18n) system.
 * Languages are PHP files in app/Lang/<code>.php that return a key=>text array.
 * Active language: cookie 'lang' -> default 'en'.
 */
final class Lang
{
    private static string $current = 'en';
    private static array $strings = [];
    private static array $fallback = [];

    /** Available languages with native name and short code label. */
    public const AVAILABLE = [
        'en' => ['name' => 'English',  'code' => 'EN'],
        'ro' => ['name' => 'Română',   'code' => 'RO'],
        'ru' => ['name' => 'Русский',  'code' => 'RU'],
        'de' => ['name' => 'Deutsch',  'code' => 'DE'],
    ];

    public static function boot(): void
    {
        // The panel language is a SINGLE, site-wide setting chosen by the owner
        // (root) in Settings — it is not per-user. Resolution order:
        //   1. the 'site_language' setting (panel_settings),
        //   2. config.php's app.locale (kept in sync with the setting),
        //   3. English.
        // During the installer (before settings/config exist) the ?setlang
        // cookie is still honoured so the wizard itself can be localized.
        $requested = (string) Settings::get('site_language', (string) Config::get('app.locale', ''));
        if ($requested === '' && !defined('CS2PANEL_INSTALLED')) {
            $requested = (string) ($_COOKIE['lang'] ?? '');
        } elseif ($requested === '' && defined('CS2PANEL_INSTALLED') && CS2PANEL_INSTALLED !== true) {
            $requested = (string) ($_COOKIE['lang'] ?? '');
        }
        if (!isset(self::AVAILABLE[$requested])) {
            $requested = 'en';
        }
        self::$current = $requested;

        // always fall back to English
        self::$fallback = self::loadFile('en');
        self::$strings  = $requested === 'en' ? self::$fallback : self::loadFile($requested);
    }

    private static function loadFile(string $code): array
    {
        $file = BASE_PATH . '/app/Lang/' . $code . '.php';
        if (is_file($file)) {
            $data = require $file;
            return is_array($data) ? $data : [];
        }
        return [];
    }

    public static function current(): string
    {
        return self::$current;
    }

    /**
     * Traduce o cheie. Suporta placeholders: t('bans.count', ['n' => 5]).
     */
    public static function t(string $key, array $params = []): string
    {
        $text = self::$strings[$key] ?? self::$fallback[$key] ?? $key;
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    /**
     * Persist the site-wide language (owner-only; enforced by the caller).
     * Stored in panel_settings and mirrored into config.php (app.locale) so it
     * applies to everyone and survives before the DB is read.
     */
    public static function setLang(string $code): void
    {
        if (!isset(self::AVAILABLE[$code])) {
            return;
        }
        Settings::set('site_language', $code);
        try {
            Config::syncFromSettings();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] language config sync skipped: ' . $e->getMessage());
        }
        self::$current = $code;
    }

    /** During the installer only: remember the chosen wizard language in a cookie. */
    public static function setInstallerCookie(string $code): void
    {
        if (isset(self::AVAILABLE[$code])) {
            setcookie('lang', $code, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
    }
}

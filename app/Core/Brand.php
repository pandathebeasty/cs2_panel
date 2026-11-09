<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Author credit ("Powered by -panda") + a light-touch tamper check.
 *
 * The panel is free to use, but the footer credit must stay visible. The credit
 * is rendered here (never hardcoded in a template) and {@see self::enforce()}
 * verifies the final HTML still contains it. If the credit was removed, the
 * page is replaced with a friendly notice instead of the app — no hard crash,
 * no obfuscation, just a request to keep the attribution.
 *
 * This is intentionally simple: anyone editing the source can work around it.
 * The goal is to make removing the credit a deliberate act, not an accident.
 */
final class Brand
{
    /** Author display name + profile. */
    private const AUTHOR      = '-panda';
    private const AUTHOR_URL  = 'https://steamcommunity.com/id/pandathebeasty/';
    private const SUPPORT_URL = 'https://www.paypal.biz/paiuvlad99';

    /** A stable marker used to detect the credit in the rendered HTML. */
    public const MARK = 'data-brand="cs2panel-credit"';

    /**
     * Footer credit, installed version and developer support link, with the current year.
     * Call this from the layout footer instead of writing the credit by hand.
     */
    public static function footer(): string
    {
        $year = date('Y');
        $url  = htmlspecialchars(self::AUTHOR_URL, ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars(self::AUTHOR, ENT_QUOTES, 'UTF-8');
        $supportUrl = htmlspecialchars(self::SUPPORT_URL, ENT_QUOTES, 'UTF-8');
        $supportLabel = htmlspecialchars(Lang::t('footer.support_developer'), ENT_QUOTES, 'UTF-8');
        $versionLabel = htmlspecialchars(Lang::t('footer.version', ['version' => Version::current()]), ENT_QUOTES, 'UTF-8');

        return '<span class="brand-footer"><span ' . self::MARK . '>Powered by '
            . '<a href="' . $url . '" target="_blank" rel="noopener">' . $name . '</a>. '
            . '&middot; ' . $year . '</span>'
            . '<span class="footer-version">' . $versionLabel . '</span>'
            . '<a class="developer-support" href="' . $supportUrl . '" target="_blank" rel="noopener noreferrer">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg>'
            . $supportLabel . '</a></span>';
    }

    /**
     * True when the rendered HTML still carries the author credit intact
     * (both the marker and the author link/name).
     */
    public static function isIntact(string $html): bool
    {
        return str_contains($html, self::MARK)
            && str_contains($html, self::AUTHOR_URL)
            && str_contains($html, self::AUTHOR);
    }

    /**
     * If the credit is intact, return the HTML unchanged. Otherwise return a
     * friendly full-page notice asking to restore the attribution.
     */
    public static function enforce(string $html): string
    {
        if (self::isIntact($html)) {
            return $html;
        }
        return self::blockedPage();
    }

    /** A styled, self-contained notice shown when the credit was removed. */
    private static function blockedPage(): string
    {
        $url  = htmlspecialchars(self::AUTHOR_URL, ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars(self::AUTHOR, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attribution required</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: radial-gradient(1200px 600px at 50% -10%, #1a1c27, #0b0c11 60%);
            color: #e8eaf2; padding: 24px;
        }
        .box {
            max-width: 520px; width: 100%; text-align: center;
            background: #15161f; border: 1px solid #262838; border-radius: 16px;
            padding: 40px 34px; box-shadow: 0 24px 60px rgba(0,0,0,.5);
        }
        .badge {
            width: 62px; height: 62px; margin: 0 auto 18px; border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #e01e37, #ff5470); color: #fff;
        }
        h1 { font-size: 21px; margin: 0 0 10px; font-weight: 800; }
        p { color: #a6aabb; font-size: 14.5px; line-height: 1.6; margin: 0 0 14px; }
        code {
            display: inline-block; background: #0b0c11; border: 1px solid #262838;
            border-radius: 8px; padding: 8px 12px; color: #e8eaf2; font-size: 13px; margin-top: 4px;
        }
        a { color: #ff5470; font-weight: 600; text-decoration: none; }
        .foot { margin-top: 22px; font-size: 12.5px; color: #6a6f82; }
    </style>
</head>
<body>
    <div class="box">
        <div class="badge">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/>
            </svg>
        </div>
        <h1>Please keep the credit</h1>
        <p>
            This panel is free to use, but the small footer credit needs to stay visible.
            It looks like it was removed, so the panel is paused until it's restored.
        </p>
        <p>Put the footer credit back (rendered via <code>Brand::footer()</code>) and reload the page.</p>
        <p class="foot">Made by <a href="{$url}" target="_blank" rel="noopener">{$name}</a> &middot; {$year}</p>
    </div>
</body>
</html>
HTML;
    }
}

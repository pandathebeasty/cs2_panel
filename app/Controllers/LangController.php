<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Lang;

final class LangController extends Controller
{
    /** Change the language and return to the previous page. */
    public function set(string $code): void
    {
        // The panel language is a single, site-wide setting. Before install it
        // is a wizard cookie; after install only the owner (root) may change it.
        $installed = defined('CS2PANEL_INSTALLED') && CS2PANEL_INSTALLED === true;
        if (!$installed) {
            Lang::setInstallerCookie($code);
        } elseif (\App\Core\Auth::check() && \App\Core\Auth::isRoot()) {
            Lang::setLang($code);
        }
        // Non-root (or guests) silently keep the site language.

                                                                               
        // The Referer already includes the deployment base; do not prefix it twice.
        $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        // If it's an absolute URL on the same host, keep only the path+query.
        if ($back !== '' && preg_match('#^https?://#', $back)) {
            // Compare hosts without the port (HTTP_HOST may include ":8080",
            // parse_url() returns host and port separately).
            $reqHost = strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':');
            $parts = parse_url($back);
            if (($parts['host'] ?? '') === $reqHost) {
                $back = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            } else {
                $back = ''; // different host -> ignore (avoid open redirect)
            }
        }

        // Only accept a safe relative path; otherwise fall back to the app root.
        if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//')) {
            $back = \App\Core\Url::to('/');
        }

        header('Location: ' . $back);
        exit;
    }
}

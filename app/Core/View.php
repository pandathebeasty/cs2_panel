<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Randare view-uri PHP simple, cu layout optional.
 */
final class View
{
    /**
     * @param string      $template ex: 'bans/index'
     * @param array       $data     variables exposed to the view
     * @param string|null $layout   'main' by default, null for no layout
     */
    public static function render(string $template, array $data = [], ?string $layout = 'main'): void
    {
        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            http_response_code(500);
            echo "View lipsa: {$template}";
            return;
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        $content = ob_get_clean();

        if ($layout === 'main' && isset($liveSearchKey) && is_string($liveSearchKey)
            && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
            && ($_SERVER['HTTP_X_PANEL_SEARCH'] ?? '') === $liveSearchKey) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, private');
            header('X-Panel-Search: ' . $liveSearchKey);
            echo self::rewriteBase($content);
            return;
        }

        if ($layout === null) {
            echo self::rewriteBase($content);
            return;
        }

        $layoutFile = BASE_PATH . '/app/Views/layouts/' . $layout . '.php';
        if (is_file($layoutFile)) {
            ob_start();
            require $layoutFile;
            $page = self::rewriteBase(ob_get_clean());
            // The full app chrome ('main') must carry the author credit.
            if ($layout === 'main') {
                $page = Brand::enforce($page);
            }
            echo $page;
        } else {
            echo self::rewriteBase($content);
        }
    }

    /**
     * When the panel runs in a subfolder (BASE_URL != ''), rewrite root-relative
     * URLs in href/src/action attributes and fetch() calls to include the prefix.
     * No-op at the domain root, so it's safe and cheap.
     */
    private static function rewriteBase(string $html): string
    {
        $base = defined('BASE_URL') ? BASE_URL : '';
        if ($base === '') {
            return $html;
        }
        // href="/x"  src="/x"  action="/x"  -> prefix with base (but not "//host" or already-prefixed)
        $html = preg_replace_callback(
            '/\b(href|src|action)=(["\'])\/(?!\/)([^"\']*)\2/i',
            function ($m) use ($base) {
                $path = '/' . $m[3];
                // avoid double-prefixing
                if (str_starts_with($path, $base . '/') || $path === $base) {
                    return $m[0];
                }
                return $m[1] . '=' . $m[2] . $base . $path . $m[2];
            },
            $html
        );
        return $html;
    }

    /** HTML escape for safe output. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * A tiny, SAFE Markdown renderer for release notes (GitHub bodies).
     * Everything is HTML-escaped first, then a small, fixed set of Markdown
     * constructs is turned into HTML. No raw HTML from the source is ever
     * emitted, so it's safe to show untrusted release text.
     *
     * Supported: headings (#, ##, ###), unordered lists (-, *), bold (**x**),
     * italic (*x* / _x_), inline code (`x`), links [text](http...), and
     * paragraph/line breaks.
     */
    public static function miniMarkdown(string $md): string
    {
        // Normalize newlines and escape everything up front.
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $esc = self::e($md);

        $lines = explode("\n", $esc);
        $html = '';
        $inList = false;
        $para = [];

        $flushPara = function () use (&$para, &$html) {
            if ($para) {
                $html .= '<p>' . self::mdInline(implode('<br>', $para)) . '</p>';
                $para = [];
            }
        };
        $closeList = function () use (&$inList, &$html) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
        };

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $flushPara();
                $closeList();
                continue;
            }
            // Headings.
            if (preg_match('/^(#{1,3})\s+(.*)$/', $trim, $m)) {
                $flushPara(); $closeList();
                $level = min(3, strlen($m[1])) + 2; // #->h3, ##->h4, ###->h5
                $html .= "<h{$level}>" . self::mdInline($m[2]) . "</h{$level}>";
                continue;
            }
            // List items ("- ", "* ").
            if (preg_match('/^[-*]\s+(.*)$/', $trim, $m)) {
                $flushPara();
                if (!$inList) { $html .= '<ul>'; $inList = true; }
                $html .= '<li>' . self::mdInline($m[1]) . '</li>';
                continue;
            }
            // Otherwise part of a paragraph.
            $closeList();
            $para[] = $trim;
        }
        $flushPara();
        $closeList();
        return $html;
    }

    /** Inline Markdown (bold/italic/code/links) on already-escaped text. */
    private static function mdInline(string $s): string
    {
        // Inline code `x`
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
        // Links [text](http...) — only http/https to stay safe.
        $s = preg_replace(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            '<a href="$2" target="_blank" rel="noopener">$1</a>',
            $s
        );
        // Bold **x**
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
        // Italic *x* or _x_
        $s = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $s);
        $s = preg_replace('/_([^_]+)_/', '<em>$1</em>', $s);
        return $s;
    }
}

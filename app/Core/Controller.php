<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base controller with common helpers.
 */
abstract class Controller
{
    protected function view(string $template, array $data = [], ?string $layout = 'main'): void
    {
        View::render($template, $data, $layout);
    }

    /**
     * Redirect to an app path (e.g. '/bans' or '/settings#discord').
     * The base path (subfolder) is prepended automatically. Absolute URLs pass through.
     */
    protected function redirect(string $url): never
    {
        if (!preg_match('#^https?://#', $url)) {
            // split optional #fragment so we prefix only the path
            $frag = '';
            if (($h = strpos($url, '#')) !== false) {
                $frag = substr($url, $h);
                $url = substr($url, 0, $h);
            }
            $url = Url::to($url) . $frag;
        }
        header('Location: ' . $url);
        exit;
    }

    protected function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    protected function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Read a POST field holding a Steam identifier and normalize ANY common
     * format (SteamID64, STEAM_0:Y:Z, [U:1:W]) to a full SteamID64.
     * If the value is empty or not a recognizable Steam id it is returned
     * trimmed and unchanged, so existing validation/error paths still apply.
     */
    protected function steamInput(string $key): string
    {
        $raw = trim((string) ($_POST[$key] ?? ''));
        if ($raw === '') {
            return '';
        }
        return Steam::toSteamId64Any($raw) ?? $raw;
    }

    protected function page(): int
    {
        return max(1, (int) ($_GET['page'] ?? 1));
    }

                   

    protected function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    protected function verifyCsrf(): void
    {
        $token = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', (string) $token)) {
            http_response_code(419);
            exit('Invalid CSRF token. Reload the page.');
        }
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Combine a split date + 24h time pair (from a <input type="date"> plus a
     * plain text "HH:MM" field) into one "Y-m-d H:i" string. Guarantees a
     * 24-hour value regardless of browser/OS locale (native datetime-local/time
     * widgets can render AM/PM even with lang="en-GB" in recent Chromium). An
     * invalid/missing time falls back to midnight; an empty date yields ''.
     */
    protected function combineDateTime(string $date, string $time): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        $time = trim($time);
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            $time = '00:00';
        }
        return $date . ' ' . $time;
    }
}

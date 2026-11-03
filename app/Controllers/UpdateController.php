<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Updater;
use App\Core\Version;

/**
 * Panel self-update from GitHub tags (root only). The UI lives in
 * Settings > Updates; these actions perform the check/apply and redirect back.
 *  - index(): redirects to Settings > Updates.
 *  - check(): force a fresh GitHub check.
 *  - run():   download the latest release and apply it.
 */
final class UpdateController extends Controller
{
    /** The updates UI now lives inside Settings > Updates. */
    public function index(): void
    {
        Auth::requireRoot();
        $this->redirect('/settings#updates');
    }

    /**
     * Force a fresh check against GitHub (ignores the cache).
     * Returns JSON for AJAX (the Updates tab shows a pop-up); otherwise redirects.
     */
    public function check(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        $latest = Updater::latest(true);
        $hasUpdate = $latest !== null && \App\Core\Version::compare($latest['version'], \App\Core\Version::current()) > 0;

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            $this->json([
                'ok'        => true,
                'reachable' => $latest !== null,
                'hasUpdate' => $hasUpdate,
                'current'   => \App\Core\Version::current(),
                'version'   => $latest['version'] ?? null,
                'codename'  => $latest['codename'] ?? null,
                // Link to the release/repo on GitHub for the "Go to Git" button.
                'url'       => $latest['url'] ?? ('https://github.com/' . \App\Core\Version::REPO),
            ]);
        }

        $this->redirect('/settings#updates');
    }

    /** Download + apply the latest release. */
    public function run(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        // Never let the update die as a blank HTTP 500: capture ANY error
        // (exception OR fatal) and turn it into a readable flash message, so
        // the user always sees what went wrong instead of a white page.
        try {
            $result = Updater::update();
        } catch (\Throwable $e) {
            $msg = 'Update failed: ' . $e->getMessage()
                . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            Updater::logError($msg);
            $this->flash('error', $msg);
            $_SESSION['update_log'] = ['Fatal error during update — see db_backups/update_error.log'];
            $this->redirect('/settings#updates');
        }

        if ($result['ok']) {
            $this->flash('success', $result['message']);
        } else {
            $this->flash('error', $result['message']);
        }
        // Keep the step-by-step log around for the page to show once.
        $_SESSION['update_log'] = $result['log'] ?? [];
        $this->redirect('/settings#updates');
    }

    /**
     * Run a single update step and return JSON progress. Drives the progress
     * HUD: the client calls this repeatedly, following the returned `next`
     * until done:true (100%) or ok:false. Each call is short, so no single
     * request can hit the host time limit (the cause of the blank 500).
     */
    public function step(): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        // A long step must not hold the session lock, or the UI's status polls
        // (same session) would block behind it.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $id = (string) $this->input('step', 'prepare');
        try {
            if (!Updater::isBackgroundStep($id)) {
                $parts = $id === 'prepare' ? array_map('strval', (array) $this->input('parts', [])) : [];
                $this->json(Updater::step($id, $parts, $id === 'prepare' && (string) $this->input('reinstall', '') === '1'));
            }
            $status = Updater::jobStatus($id);
            if ($status !== null) {
                $this->json($status);
            }
            Updater::startJob($id);
            if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
                $this->respondAndDetach(Updater::pendingResult($id));
                Updater::runJob($id);
                exit;
            }
            // No way to end the response early: run inline. If a proxy times
            // out meanwhile, PHP keeps going and the UI picks up the stored result.
            $result = Updater::runJob($id);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            Updater::logError('Step "' . $id . '" fatal: ' . $msg);
            $this->json(['ok' => false, 'done' => false, 'step' => $id, 'next' => null, 'percent' => 0, 'label' => '', 'message' => $msg]);
        }
        $this->json($result);
    }

    /** Send a JSON response and close the connection, keeping PHP running. */
    private function respondAndDetach(array $payload): void
    {
        @ignore_user_abort(true);
        $body = (string) json_encode($payload);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        echo $body;
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }
}

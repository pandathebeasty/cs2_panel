<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\Totp;
use App\Models\TwoFactorModel;

/**
 * Staff two-factor authentication: the post-Steam code challenge and the
 * account security page (enable / disable / recovery codes).
 */
final class TwoFactorController extends Controller
{
    /** Code prompt shown after a successful Steam login. */
    public function challenge(): void
    {
        $user = Auth::pendingTwoFactor();
        if ($user === null) {
            $this->redirect(Auth::hasSteam() ? '/' : '/login');
        }
        $this->view('auth/2fa', ['pendingUser' => $user, 'csrf' => $this->csrfToken()], 'blank');
    }

    public function verify(): void
    {
        $this->verifyCsrf();
        $user = Auth::pendingTwoFactor();
        if ($user === null) {
            $this->flash('error', Lang::t('2fa.expired'));
            $this->redirect('/login');
        }
        $steamId = (string) $user['steamid'];
        $result = TwoFactorModel::verify($steamId, (string) $this->input('code', ''));
        if ($result === 'ok' || $result === 'recovery') {
            Auth::completeTwoFactor();
            Logger::log($result === 'recovery' ? 'auth.2fa_recovery_used' : 'auth.login', $steamId, $result === 'ok' ? '2FA' : null);
            if ($result === 'recovery') {
                $this->flash('warning', Lang::t('2fa.recovery_used'));
            }
            $this->redirect('/');
        }
        $who = trim((string) ($user['name'] ?? '')) . ' · IP ' . Logger::clientIp();
        if ($result === 'locked') {
            Logger::log('auth.2fa_locked', $steamId, $who);
            $this->flash('error', Lang::t('2fa.locked'));
        } else {
            Logger::log('auth.2fa_failed', $steamId, $who);
            $this->flash('error', Lang::t('2fa.invalid'));
        }
        $this->redirect('/2fa');
    }

    public function cancel(): void
    {
        unset($_SESSION['2fa_pending']);
        $this->redirect('/login');
    }

    /** Account security page for staff. */
    public function index(): void
    {
        Auth::requireSteam();
        if (!Auth::isAdmin()) {
            $this->redirect('/');
        }
        $steamId = (string) Auth::steamId();
        $row = TwoFactorModel::find($steamId);
        $setup = null;
        if ($row === null) {
            $secret = $_SESSION['2fa_setup'] ?? '';
            if (!is_string($secret) || $secret === '') {
                $secret = Totp::generateSecret();
                $_SESSION['2fa_setup'] = $secret;
            }
            $issuer = (string) Settings::get('site_name', 'CS2 Panel');
            $account = (string) (Auth::user()['name'] ?? $steamId);
            $setup = ['secret' => $secret, 'uri' => Totp::uri($secret, $account, $issuer)];
        }
        $codes = $_SESSION['2fa_new_codes'] ?? null;
        unset($_SESSION['2fa_new_codes']);
        $this->view('account/security', [
            'row'       => $row,
            'setup'     => $setup,
            'required'  => TwoFactorModel::required(),
            'newCodes'  => is_array($codes) ? $codes : null,
            'remaining' => $row ? TwoFactorModel::remainingRecovery($row) : 0,
            'csrf'      => $this->csrfToken(),
        ]);
    }

    public function enable(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();
        if (!Auth::isAdmin()) {
            $this->redirect('/');
        }
        $steamId = (string) Auth::steamId();
        $secret = (string) ($_SESSION['2fa_setup'] ?? '');
        $step = $secret !== '' ? Totp::verify($secret, (string) $this->input('code', '')) : null;
        if ($step === null) {
            $this->flash('error', Lang::t('2fa.invalid'));
            $this->redirect('/account/security');
        }
        $_SESSION['2fa_new_codes'] = TwoFactorModel::enable($steamId, $secret, $step);
        unset($_SESSION['2fa_setup']);
        Auth::markTwoFactorVerified();
        Logger::log('auth.2fa_enabled', $steamId);
        $this->flash('success', Lang::t('2fa.enabled_ok'));
        $this->redirect('/account/security');
    }

    public function disable(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();
        $steamId = (string) Auth::steamId();
        if (TwoFactorModel::required() && !Auth::isOwner()) {
            $this->flash('error', Lang::t('2fa.cannot_disable'));
            $this->redirect('/account/security');
        }
        $result = TwoFactorModel::verify($steamId, (string) $this->input('code', ''));
        if ($result !== 'ok' && $result !== 'recovery') {
            $this->flash('error', Lang::t($result === 'locked' ? '2fa.locked' : '2fa.invalid'));
            $this->redirect('/account/security');
        }
        TwoFactorModel::disable($steamId);
        Logger::log('auth.2fa_disabled', $steamId);
        $this->flash('success', Lang::t('2fa.disabled_ok'));
        $this->redirect('/account/security');
    }

    public function regenerate(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();
        $steamId = (string) Auth::steamId();
        $result = TwoFactorModel::verify($steamId, (string) $this->input('code', ''));
        if ($result !== 'ok') {
            $this->flash('error', Lang::t($result === 'locked' ? '2fa.locked' : '2fa.invalid'));
            $this->redirect('/account/security');
        }
        $_SESSION['2fa_new_codes'] = TwoFactorModel::regenerateRecovery($steamId);
        Logger::log('auth.2fa_recovery_regenerated', $steamId);
        $this->redirect('/account/security');
    }

    /** Owner: reset 2FA for a staff member who lost their device. */
    public function reset(string $steamId): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        TwoFactorModel::disable($steamId);
        Logger::log('auth.2fa_reset', $steamId);
        $this->flash('success', Lang::t('2fa.reset_ok'));
        $this->redirect('/system#security');
    }
}

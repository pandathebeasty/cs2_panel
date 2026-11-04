<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Installer;
use App\Core\Lang;
use App\Core\Steam;

final class InstallController extends Controller
{
    /** Wizard page (single step). */
    public function index(): void
    {
        if (Installer::isInstalled()) {
            $this->redirect('/');
        }
        $this->view('install/index', [
            'csrf'   => $this->csrfToken(),
            'errors' => $_SESSION['install_errors'] ?? [],
            'old'    => $_SESSION['install_old'] ?? [],
            // Suggested realm = origin + base path (subfolder aware), no trailing slash.
            'suggestedRealm' => \App\Core\Url::origin() . \App\Core\Url::base(),
        ], 'install');
        unset($_SESSION['install_errors'], $_SESSION['install_old']);
    }

    /** Process the wizard submission. */
    public function run(): void
    {
        if (Installer::isInstalled()) {
            $this->redirect('/');
        }
        $this->verifyCsrf();

        $errors = [];
        $input = $_POST;

        // Required fields (label => field name).
        $required = [
            Lang::t('install.db_host_label')  => 'db_host',
            Lang::t('install.db_name')        => 'db_name',
            Lang::t('install.db_user_label')  => 'db_user',
            Lang::t('install.site_url_label') => 'realm',
            'Root SteamID64'                  => 'root_steamid',
        ];
        foreach ($required as $label => $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                $errors[] = Lang::t('install.err_required', ['field' => $label]);
            }
        }

        $rootSteam = trim((string) ($input['root_steamid'] ?? ''));
        if ($rootSteam !== '' && !Steam::isValidSteamId64($rootSteam)) {
            $errors[] = Lang::t('install.err_steamid');
        }

        // Test DB connection
        $pdo = null;
        if (!$errors) {
            try {
                $pdo = Installer::testConnection([
                    'host'     => $input['db_host'],
                    'port'     => (int) ($input['db_port'] ?: 3306),
                    'name'     => $input['db_name'],
                    'user'     => $input['db_user'],
                    'password' => $input['db_password'] ?? '',
                ]);
            } catch (\Throwable $e) {
                $errors[] = Lang::t('install.err_db', ['e' => $e->getMessage()]);
            }
        }

        $extdb = [];
        if (!$errors && $pdo !== null) {
            $extdb = Installer::integrationDbInput($input, $pdo, $errors);
        }

        if ($errors) {
            $_SESSION['install_errors'] = $errors;
            $_SESSION['install_old'] = $input;
            $this->redirect('/install');
        }

        // Run schema + create root + write config
        try {
            $preserveExistingSettings = Installer::hasPanelSettings($pdo);
            Installer::runPanelSchema($pdo);
            Installer::createRoot($pdo, $rootSteam, trim((string) ($input['root_name'] ?? 'Root')) ?: 'Root');
            Installer::saveInitialSettings($pdo, $input, $preserveExistingSettings, $extdb);

            $configContents = Installer::buildConfig($input);
            if (!Installer::writeConfig($configContents)) {
                throw new \RuntimeException(Lang::t('install.err_config'));
            }
        } catch (\Throwable $e) {
            $_SESSION['install_errors'] = [Lang::t('install.err_install', ['e' => $e->getMessage()])];
            $_SESSION['install_old'] = $input;
            $this->redirect('/install');
        }

        // Notify Discord that the installation succeeded and by whom.
        $webhook = trim((string) ($input['discord_webhook'] ?? ''));
        if ($webhook !== '') {
            \App\Core\Discord::sendToWebhook(
                $webhook,
                'Panel Installed',
                'unban',
                [
                    'Site'       => $input['site_name'] ?: 'CS2 Panel',
                    'Root SteamID' => $rootSteam,
                    'Root Name'  => trim((string) ($input['root_name'] ?? 'Root')) ?: 'Root',
                    'Installer IP' => \App\Core\Logger::clientIp(),
                    'URL'        => rtrim((string) ($input['realm'] ?? ''), '/'),
                ],
                'Installation completed at ' . date('Y-m-d H:i:s')
            );
        }

        $this->flash('success', Lang::t('install.success'));
        $this->redirect('/login');
    }
}

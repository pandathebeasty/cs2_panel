<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Rcon;
use App\Models\ServerModel;

final class ServerController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        $servers = ServerModel::all();

        // Enrich each server with a live A2S query (map, players, status).
        // Short per-server timeout so a dead server doesn't stall the page.
        $timeout = (float) Config::get('query.timeout', 1.0);
        foreach ($servers as &$srv) {
            [$host, $port] = self::splitAddress((string) $srv['address']);
            $info = \App\Core\A2S::info($host, (int) $port, $timeout);
            $srv['live']        = $info;
            $srv['connect_url'] = ($host !== '' && $port) ? 'steam://connect/' . $host . ':' . $port : '';
            $srv['map_image']   = \App\Core\ServerMap::image($info['map'] ?? null);
        }
        unset($srv);

        $this->view('servers/index', [
            'servers'   => $servers,
            // RCON is powerful (arbitrary server commands) — restrict to root.
            'canRcon'   => Auth::isRoot(),
            'canManage' => Auth::canManage(),
            'canModerate' => Auth::canModerate(),
            'csrf'      => $this->csrfToken(),
        ]);
    }

    /** Split an "ip:port" address into [host, port] with a sensible default port. */
    private static function splitAddress(string $address): array
    {
        return \App\Core\A2S::endpoint($address);
    }

    public function info(string $id): void
    {
        Auth::requireLogin();
        $this->sendServerInfo($id);
    }

    public function loginInfo(string $id): void
    {
        $this->sendServerInfo($id);
    }

    private function sendServerInfo(string $id): void
    {
        header('Cache-Control: no-store');
        if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
            error_log('[CS2Panel] Server info could not release the session lock.');
        }
        $server = ServerModel::publicDetails((int) $id);
        if (!$server) {
            $this->json(['ok' => false, 'error' => \App\Core\Lang::t('servers.none')], 404);
        }
        [$host, $port] = self::splitAddress((string) $server['address']);
        if ($host === '') {
            $this->json(['ok' => false, 'error' => \App\Core\Lang::t('portal.server_unavailable')], 422);
        }
        $timeout = max(.1, min(2.0, (float) Config::get('query.timeout', 1.5)));
        $live = \App\Core\A2S::info($host, $port, $timeout);
        $this->json([
            'ok' => true,
            'id' => (int) $server['id'],
            'online' => $live['online'],
            'name' => $live['name'] ?? (string) $server['hostname'],
            'map' => $live['map'],
            'map_label' => \App\Core\ServerMap::label($live['map']),
            'map_image' => \App\Core\ServerMap::image($live['map']),
            'players' => $live['players'],
            'max_players' => $live['max_players'],
        ]);
    }

    public function store(): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $hostname = trim((string) $this->input('hostname', ''));
        $address  = trim((string) $this->input('address', ''));
        if ($hostname === '' || $address === '') {
            $this->flash('error', 'Hostname and address are required.');
            $this->redirect('/servers');
        }

        $id = ServerModel::create($hostname, $address, trim((string) $this->input('rcon_password', '')));
        \App\Core\Logger::log('server.create', (string) $id, $address);
        $this->flash('success', 'Server added.');
        $this->redirect('/servers');
    }

    public function update(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();

        $hostname = trim((string) $this->input('hostname', ''));
        $address  = trim((string) $this->input('address', ''));
        $rcon     = (string) $this->input('rcon_password', '');
        // Empty password field means "keep the existing one".
        ServerModel::update((int) $id, $hostname, $address, $rcon === '' ? null : $rcon);
        \App\Core\Logger::log('server.update', $id, $address);
        $this->flash('success', 'Server updated.');
        $this->redirect('/servers');
    }

    public function delete(string $id): void
    {
        Auth::requireManage();
        $this->verifyCsrf();
        ServerModel::delete((int) $id);
        \App\Core\Logger::log('server.delete', $id);
        $this->flash('success', 'Server deleted.');
        $this->redirect('/servers');
    }

    /**
     * Live player list of a server, read over RCON ("status"). JSON, with a
     * short timeout so a dead server never stalls the page.
     */
    public function players(string $id): void
    {
        Auth::requireModerate();

        $server = ServerModel::find((int) $id);
        if (!$server) {
            $this->json(['ok' => false, 'error' => 'Server does not exist.'], 404);
        }
        if (empty($server['rcon_password'])) {
            $this->json(['ok' => false, 'error' => \App\Core\Lang::t('servers.rcon_needpass')], 200);
        }

        try {
            [$host, $port] = self::splitAddress((string) $server['address']);
            $rcon = new Rcon($host, (int) $port, (string) $server['rcon_password'], (int) Config::get('rcon.timeout', 3));
            $rcon->connect();
            try {
                $statusRaw = $rcon->command('status');
                $players = \App\Core\ServerStatus::parse($statusRaw);
                $maxPlayers = \App\Core\ServerStatus::maxPlayers($statusRaw);
                foreach ($players as $player) {
                    if ($player['steamid64'] === '' && !$player['bot']) {
                        // CS2 "status" no longer prints SteamIDs; status_json does.
                        try {
                            $players = \App\Core\ServerStatus::enrichFromJson($players, $rcon->command('status_json'));
                        } catch (\Throwable $e) {
                            error_log('[CS2Panel] status_json failed for server #' . (int) $server['id'] . ': ' . $e->getMessage());
                        }
                        break;
                    }
                }
            } finally {
                $rcon->disconnect();
            }
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }

        if ($maxPlayers === null) {
            [$host, $port] = self::splitAddress((string) $server['address']);
            $maxPlayers = \App\Core\A2S::info($host, (int) $port, (float) Config::get('query.timeout', 1.0))['max_players'] ?? null;
        }

        $avatars = [];
        $ids = array_values(array_filter(array_column($players, 'steamid64')));
        if ($ids) {
            $avatars = \App\Core\Steam::avatars($ids);
        }
        foreach ($players as &$p) {
            $p['avatar']      = $avatars[$p['steamid64']] ?? \App\Core\Steam::avatarPlaceholder();
            $p['profile_url'] = $p['steamid64'] !== '' ? \App\Core\Steam::profileUrl($p['steamid64']) : '';
        }
        unset($p);

        $this->json(['ok' => true, 'players' => $players, 'count' => count($players), 'max' => $maxPlayers]);
    }

    /** Moderation commands that can be fired at a connected player. */
    private const PLAYER_ACTIONS = [
        'kick'    => 'css_kick',
        'ban'     => 'css_ban',
        'mute'    => 'css_mute',
        'gag'     => 'css_gag',
        'silence' => 'css_silence',
    ];

    /**
     * Run a moderation command against a connected player. The target is always
     * addressed by "#userid" — names are ambiguous and break on spaces/unicode.
     */
    public function playerAction(string $id): void
    {
        Auth::requireModerate();
        $this->verifyCsrf();

        $server = ServerModel::find((int) $id);
        if (!$server) {
            $this->json(['ok' => false, 'error' => 'Server does not exist.'], 404);
        }
        if (empty($server['rcon_password'])) {
            $this->json(['ok' => false, 'error' => \App\Core\Lang::t('servers.rcon_needpass')], 400);
        }

        $action = (string) $this->input('action', '');
        if (!isset(self::PLAYER_ACTIONS[$action])) {
            $this->json(['ok' => false, 'error' => 'Unknown action.'], 400);
        }
        $userId = (int) $this->input('userid', 0);
        if ($userId <= 0) {
            $this->json(['ok' => false, 'error' => 'Invalid player.'], 400);
        }

        // Duration in minutes (0 = permanent). Kick takes no duration.
        $minutes = max(0, min(525600, (int) $this->input('minutes', 0)));
        $reason  = self::sanitizeReason((string) $this->input('reason', ''));

        $cmd = self::PLAYER_ACTIONS[$action] . ' #' . $userId;
        if ($action !== 'kick') {
            $cmd .= ' ' . $minutes;
        }
        if ($reason !== '') {
            $cmd .= ' "' . $reason . '"';
        }

        try {
            $output = $this->rconExec($server, $cmd);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }

        \App\Core\Logger::log(
            'server.' . $action,
            (string) $this->input('steamid', '') ?: null,
            trim((string) $this->input('player_name', '')) . ' @ ' . $server['hostname'] . ' | ' . $cmd
        );

        $this->json(['ok' => true, 'command' => $cmd, 'output' => $output]);
    }

    /**
     * Strip anything that could break out of the quoted argument or inject a
     * second console command.
     */
    private static function sanitizeReason(string $reason): string
    {
        $reason = preg_replace('/[\x00-\x1F";]+/u', ' ', trim($reason)) ?? '';
        return mb_substr(trim($reason), 0, 120);
    }

    /** Open RCON, run one command, close. @throws \Throwable */
    private function rconExec(array $server, string $command): string
    {
        [$host, $port] = self::splitAddress((string) $server['address']);
        $rcon = new Rcon($host, (int) $port, (string) $server['rcon_password'], (int) Config::get('rcon.timeout', 3));
        $rcon->connect();
        try {
            return $rcon->command($command);
        } finally {
            $rcon->disconnect();
        }
    }

    /**
     * Execute an RCON command. JSON response (AJAX), with a short timeout so it does not
     * block the panel if the server is down.
     */
    public function rcon(string $id): void
    {
        // RCON runs arbitrary server commands — root only.
        Auth::requireRoot();
        $this->verifyCsrf();

        $server = ServerModel::find((int) $id);
        if (!$server) {
            $this->json(['ok' => false, 'error' => 'Server does not exist.'], 404);
        }
        if (empty($server['rcon_password'])) {
            $this->json(['ok' => false, 'error' => 'This server has no RCON password set.'], 400);
        }

        $command = trim((string) $this->input('command', ''));
        if ($command === '') {
            $this->json(['ok' => false, 'error' => 'Empty command.'], 400);
        }

        try {
            $output = $this->rconExec($server, $command);
            $this->json(['ok' => true, 'output' => $output !== '' ? $output : '(no response)']);
        } catch (\Throwable $e) {
            // Do not throw 500 - return the error gracefully to the UI
            $this->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Steam authentication via OpenID 2.0 (no external libraries).
 * Flow:
 *   1. redirectUrl() -> send the user to Steam
 *   2. Steam returns to return_url with openid.* parameters
 *   3. validate() -> confirms the response with the Steam server and extracts the SteamID64
 */
final class SteamOpenID
{
    private const OP_ENDPOINT = 'https://steamcommunity.com/openid/login';

    /**
     * The site base URL used for the OpenID realm + return_to. Prefers the
     * live "website_url" setting (editable in Settings), so moving the panel to
     * a different domain/subfolder takes effect immediately without re-running
     * the installer or hand-editing config.php. Falls back to config.php's
     * steam.realm when the setting is empty.
     */
    public static function baseUrl(): string
    {
        $url = trim((string) Settings::get('website_url', ''));
        if ($url === '') {
            $url = (string) Config::get('steam.realm', '');
        }
        return rtrim($url, '/');
    }

    public static function redirectUrl(): string
    {
        $realm    = self::baseUrl();
        $returnTo = $realm !== ''
            ? $realm . '/auth/steam/return'
            : (string) Config::get('steam.return_url');
        if ($realm === '') {
            $realm = (string) Config::get('steam.realm');
        }

        $params = [
            'openid.ns'         => 'http://specs.openid.net/auth/2.0',
            'openid.mode'       => 'checkid_setup',
            'openid.return_to'  => $returnTo,
            'openid.realm'      => $realm,
            'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ];

        return self::OP_ENDPOINT . '?' . http_build_query($params);
    }

       
                                                              
       
    public static function validate(array $get): ?string
    {
        if (($get['openid_mode'] ?? '') !== 'id_res') {
            return null;
        }

        // Rebuild the verification request with mode=check_authentication
        $params = [];
        foreach ($get as $key => $value) {
            if (strncmp($key, 'openid_', 7) === 0) {
                $params['openid.' . substr($key, 7)] = $value;
            }
        }
        $params['openid.mode'] = 'check_authentication';

        $response = self::post(self::OP_ENDPOINT, $params);
        if ($response === null || !str_contains($response, 'is_valid:true')) {
            return null;
        }

        // Extragem SteamID64 din claimed_id
        $claimed = $get['openid_claimed_id'] ?? '';
        if (preg_match('#^https?://steamcommunity\.com/openid/id/(\d{17})$#', $claimed, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function post(string $url, array $params): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result === false ? null : (string) $result;
    }

    /**
     * Fetch the name and avatar from the Steam Web API (optional, if an api_key is set).
     * @return array{name:string, avatar:string}|null
     */
    public static function fetchProfile(string $steamId64): ?array
    {
        $key = Steam::apiKey();
        if ($key === '') {
            return null;
        }

        $url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key='
            . urlencode($key) . '&steamids=' . urlencode($steamId64);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $json = curl_exec($ch);
        curl_close($ch);

        if ($json === false) {
            return null;
        }
        $data = json_decode((string) $json, true);
        $player = $data['response']['players'][0] ?? null;
        if (!$player) {
            return null;
        }
        return [
            'name'   => $player['personaname'] ?? 'Unknown',
            'avatar' => $player['avatarfull'] ?? Steam::avatarPlaceholder(),
        ];
    }
}

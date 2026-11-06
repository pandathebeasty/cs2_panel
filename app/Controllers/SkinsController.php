<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\WeaponPaintsData;
use App\Models\WeaponPaintsModel;

/**
 * WeaponPaints "Skins" loadout editor. Panel login only — every wp_player_*
 * query is keyed on the session SteamID64 (Auth::steamId()).
 *
 * Native port of the standalone module's index.php: it assembles the catalog
 * (weapons by category, knives, gloves, agents split CT/T, music, collectibles)
 * and the player's current selections, then renders the panel-themed view. The
 * equip POST mirrors the source's AJAX contract ({status:"success"}).
 */
final class SkinsController extends Controller
{
    public function index(): void
    {
        // Skins are keyed on the session SteamID64, so a guest can't have a
        // loadout — show the "log in with Steam" prompt instead of the editor.
        Auth::requireSteam();

        if (!WeaponPaintsModel::enabled()) {
            $this->view('skins/disabled', [], 'main');
            return;
        }

        $steamid = (string) (Auth::steamId() ?? '');

        // Data hygiene: drop any stray wrong-team rows for forced-team weapons
        // (e.g. a CT skin saved on an AK). Keeps both the panel display AND the
        // in-game plugin (which reads the same tables) consistent. Cheap, and
        // idempotent once the data is clean.
        WeaponPaintsModel::normalizeForcedTeams($steamid);
        // Drop stale "Both" rows for weapons that now have per-team skins set.
        WeaponPaintsModel::normalizeStaleBoth($steamid);

        // Catalog
        $weapons = WeaponPaintsData::getWeaponsFromArray();
        $skins = WeaponPaintsData::skinsFromJson();
        $knifes = WeaponPaintsData::getKnifeTypes();
        $gloves = WeaponPaintsData::getGloveTypes();
        $gloveSkinsAll = WeaponPaintsData::glovesFromJson();
        $allAgents = WeaponPaintsData::agentsFromJson();
        $allMusic = WeaponPaintsData::musicFromJson();
        $allCollectibles = WeaponPaintsData::collectiblesFromJson();
        // Stickers + keychains for the weapon picker (only usable if the DB has
        // the inline columns; otherwise the picker stays hidden client-side).
        $allStickers = WeaponPaintsData::stickersFromJson();
        $allKeychains = WeaponPaintsData::keychainsFromJson();
        $stickersEnabled = WeaponPaintsModel::hasStickerColumns();
        $groupedWeapons = WeaponPaintsData::groupWeaponsByCategory($weapons);

        $agentsCT = [];
        $agentsT = [];
        foreach ($allAgents as $agent) {
            $team = (int) ($agent['team'] ?? 0);
            if ($team === WeaponPaintsData::TEAM_CT) {
                $agentsCT[] = $agent;
            } elseif ($team === WeaponPaintsData::TEAM_T) {
                $agentsT[] = $agent;
            }
        }

        // Player selections (keyed on the session SteamID64)
        $selectedSkins = WeaponPaintsData::getSelectedSkins(WeaponPaintsModel::playerSkins($steamid));

        $selectedKnives = [];
        foreach (WeaponPaintsModel::playerKnives($steamid) as $r) {
            $selectedKnives[(int) ($r['weapon_team'] ?? WeaponPaintsData::TEAM_BOTH)] = $r;
        }

        $selectedGloves = [];
        foreach (WeaponPaintsModel::playerGloves($steamid) as $r) {
            $selectedGloves[(int) ($r['weapon_team'] ?? WeaponPaintsData::TEAM_BOTH)] = $r;
        }

        $selectedAgents = WeaponPaintsModel::playerAgents($steamid);

        $selectedMusic = [];
        foreach (WeaponPaintsModel::playerMusic($steamid) as $r) {
            $selectedMusic[(int) ($r['weapon_team'] ?? WeaponPaintsData::TEAM_BOTH)] = (int) $r['music_id'];
        }

        $selectedPins = [];
        foreach (WeaponPaintsModel::playerPins($steamid) as $r) {
            $selectedPins[(int) ($r['weapon_team'] ?? WeaponPaintsData::TEAM_BOTH)] = (int) $r['id'];
        }

        $viewData = [
            'weapons' => $weapons,
            'skins' => $skins,
            'knifes' => $knifes,
            'gloves' => $gloves,
            'gloveSkinsAll' => $gloveSkinsAll,
            'groupedWeapons' => $groupedWeapons,
            'agentsCT' => $agentsCT,
            'agentsT' => $agentsT,
            'allMusic' => $allMusic,
            'allCollectibles' => $allCollectibles,
            'allStickers' => $allStickers,
            'allKeychains' => $allKeychains,
            'stickersEnabled' => $stickersEnabled,
            'selectedSkins' => $selectedSkins,
            'selectedKnives' => $selectedKnives,
            'selectedGloves' => $selectedGloves,
            'selectedAgents' => $selectedAgents,
            'selectedMusic' => $selectedMusic,
            'selectedPins' => $selectedPins,
            'csrf' => $this->csrfToken(),
        ];

        // AJAX loadout refresh (after an equip): render ONLY the fragment the
        // client swaps in (sidebar + sections + equipped-data), with no layout.
        // Much cheaper than re-rendering the whole page, so equipping is snappy.
        if ($this->isAjax()) {
            $this->view('skins/_refresh', $viewData, null);
            return;
        }

        $this->view('skins/index', $viewData);
    }

    public function save(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();

        if (!WeaponPaintsModel::enabled()) {
            if ($this->isAjax()) {
                $this->json(['status' => 'error']);
            }
            $this->redirect('/skins');
        }

        $steamid = (string) (Auth::steamId() ?? '');
        try {
            WeaponPaintsModel::save($_POST, $steamid);
        } catch (\InvalidArgumentException | \UnexpectedValueException $exception) {
            error_log('[Skins] Keychain placement rejected: ' . $exception->getMessage());
            $unavailable = $exception instanceof \UnexpectedValueException;
            $message = Lang::t($unavailable ? 'skins.keychain_limits_unavailable' : 'skins.keychain_position_invalid');
            if ($this->isAjax()) {
                $this->json(['status' => 'error', 'message' => $message], $unavailable ? 503 : 400);
            }
            $this->flash('error', $message);
            $this->redirect('/skins');
        }

        if ($this->isAjax()) {
            $this->json(['status' => 'success']);
        }
        $this->redirect('/skins');
    }

    private function isAjax(): bool
    {
        if (!empty($_POST['ajax'])) {
            return true;
        }
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return strtolower((string) $xrw) === 'xmlhttprequest';
    }
}

<?php
/**
 * Skins picker modal — team scope, wear/seed/float, nametag, StatTrak. Posts to
 * /skins/save with the hidden `forma` + CSRF token. Panel-native markup (reuses
 * .modal-overlay / .modal).
 *
 * @var string $csrf
 */

use App\Core\Lang;
use App\Core\View;
?>
<div class="modal-overlay" id="skinsModal">
    <div class="modal skins-modal">
        <form method="POST" action="/skins/save" id="skinsConfigForm">
            <input type="hidden" name="forma" id="skinsFormaInput" value="">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">

            <div class="modal-head skins-modal-head">
                <div class="skins-modal-thumb"><img id="skinsModalImage" src="" alt=""></div>
                <div class="skins-modal-titles">
                    <p id="skinsModalWeaponName" class="skins-modal-weapon"></p>
                    <p id="skinsModalPaintName" class="skins-modal-paint"></p>
                </div>
                <button type="button" class="modal-close" id="skinsModalClose">&times;</button>
            </div>

            <div class="skins-3d" id="skins3dStage" hidden>
                <div class="skins-3d-canvas" id="skins3dCanvas"></div>
                <div class="skins-3d-loader" aria-hidden="true"><span></span></div>
                <span class="skins-3d-notex"><?= View::e(Lang::t('skins.texture_missing')) ?></span>
                <img class="skins-3d-fallback" id="skins3dFallback" src="" alt="">
                <button type="button" class="skins-3d-reset" id="skins3dReset" title="<?= View::e(Lang::t('skins.view_reset')) ?>" aria-label="<?= View::e(Lang::t('skins.view_reset')) ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
                </button>
            </div>

            <!-- Tabs: Skin / Stickers / Keychain. Stickers & Keychain tabs are
                 shown by JS only for weapon skins when the backend supports it. -->
            <div class="skins-modal-tabs" id="skinsModalTabs">
                <button type="button" class="skins-modal-tab active" data-mtab="skin"><?= View::e(Lang::t('skins.tab_skin')) ?></button>
                <button type="button" class="skins-modal-tab" data-mtab="stickers" id="skinsTabBtnStickers" style="display:none"><?= View::e(Lang::t('skins.stickers')) ?></button>
                <button type="button" class="skins-modal-tab" data-mtab="keychain" id="skinsTabBtnKeychain" style="display:none"><?= View::e(Lang::t('skins.keychain')) ?></button>
            </div>


            <div class="modal-body skins-modal-body">
                <!-- Team scope (shared across tabs) -->
                <div class="field">
                    <label><?= View::e(Lang::t('skins.apply_to')) ?></label>
                    <input type="hidden" name="team" id="skinsTeamInput" value="1">
                    <div class="skins-team-btns" id="skinsTeamButtons">
                        <button type="button" class="skins-team-btn" data-team="1"><?= View::e(Lang::t('skins.both')) ?></button>
                        <button type="button" class="skins-team-btn" data-team="2"><?= View::e(Lang::t('skins.terrorists')) ?></button>
                        <button type="button" class="skins-team-btn" data-team="3"><?= View::e(Lang::t('skins.counter_terrorists')) ?></button>
                    </div>
                    <p id="skinsTeamForcedNote" class="hint" style="display:none"></p>
                </div>

                <!-- Skin-only fields -->
                <div id="skinsOnlyFields">
                    <div class="skins-tabpanel" data-mpanel="skin">
                    <div class="field">
                        <label><?= View::e(Lang::t('skins.wear_label')) ?></label>
                        <select id="skinsWearSelect">
                            <option value="0.00"><?= View::e(Lang::t('skins.wear_factory_new')) ?></option>
                            <option value="0.07"><?= View::e(Lang::t('skins.wear_minimal_wear')) ?></option>
                            <option value="0.15"><?= View::e(Lang::t('skins.wear_field_tested')) ?></option>
                            <option value="0.38"><?= View::e(Lang::t('skins.wear_well_worn')) ?></option>
                            <option value="0.45"><?= View::e(Lang::t('skins.wear_battle_scarred')) ?></option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="skinsWearInput"><?= View::e(Lang::t('skins.float_value')) ?></label>
                            <input type="number" step="0.000001" min="0" max="1" name="wear" id="skinsWearInput" value="0.00">
                            <div class="skins-float-presets" id="skinsFloatPresets">
                                <button type="button" data-preset="0.00">0.00</button>
                                <button type="button" data-preset="0.07">0.07</button>
                                <button type="button" data-preset="0.15">0.15</button>
                                <button type="button" data-preset="0.38">0.38</button>
                                <button type="button" data-preset="0.45">0.45</button>
                            </div>
                        </div>
                        <div class="field">
                            <label for="skinsSeedInput"><?= View::e(Lang::t('skins.seed_pattern')) ?></label>
                            <input type="number" step="1" min="0" max="1000" name="seed" id="skinsSeedInput" value="0">
                        </div>
                    </div>

                    <div class="field" id="skinsNametagField">
                        <label for="skinsNametagInput"><?= View::e(Lang::t('skins.nametag_label')) ?> <span class="hint">max 32</span></label>
                        <input type="text" maxlength="32" name="nametag" id="skinsNametagInput" placeholder="<?= View::e(Lang::t('skins.nametag_placeholder')) ?>">
                    </div>

                    <!-- StatTrak -->
                    <div class="skins-stattrak" id="skinsStattrakWrap">
                        <label class="skins-check">
                            <input type="checkbox" name="stattrak" id="skinsStattrakToggle" value="1">
                            <span>StatTrak™ — <?= View::e(Lang::t('skins.enable_stattrak')) ?></span>
                        </label>
                        <div id="skinsStattrakCountWrap" style="display:none">
                            <label for="skinsStattrakCountInput"><?= View::e(Lang::t('skins.stattrak_kills')) ?></label>
                            <input type="number" min="0" max="999999" name="stattrak_count" id="skinsStattrakCountInput" value="0">
                            <p class="hint"><?= View::e(Lang::t('skins.stattrak_hint')) ?></p>
                        </div>
                    </div>
                    </div><!-- /panel skin -->

                    <!-- Stickers tab: 5 slots. Click a slot to pick a sticker. With the
                         3D preview, a selected sticker can be placed on the model. -->
                    <div class="skins-tabpanel" data-mpanel="stickers" style="display:none">
                        <div class="skins-slot-row" id="skinsStickerSlots">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <button type="button" class="skins-slot" data-sticker-slot="<?= $i ?>" title="<?= View::e(Lang::t('skins.slot_n', [$i + 1])) ?>">
                                    <input type="hidden" name="stickers[<?= $i ?>]" value="0">
                                    <input type="hidden" name="sticker_pos[<?= $i ?>]" value="0;0;0">
                                    <span class="skins-slot-img"></span>
                                    <span class="skins-slot-num"><?= $i + 1 ?></span>
                                    <span class="skins-slot-clear" data-sticker-clear="<?= $i ?>" title="<?= View::e(Lang::t('skins.clear_slot')) ?>">&times;</span>
                                </button>
                            <?php endfor; ?>
                        </div>
                        <div class="skins-place" id="skinsPlaceBar" hidden>
                            <p class="hint" id="skinsPlaceHint"><?= View::e(Lang::t('skins.place_hint')) ?></p>
                            <div class="skins-place-row">
                                <label for="skinsPlaceRot"><?= View::e(Lang::t('skins.rotation')) ?></label>
                                <input type="range" id="skinsPlaceRot" min="-180" max="180" step="1" value="0">
                                <output id="skinsPlaceRotOut">0°</output>
                                <button type="button" class="btn btn-ghost btn-sm" id="skinsPlaceReset"><?= View::e(Lang::t('skins.place_default')) ?></button>
                            </div>
                        </div>
                    </div>

                    <!-- Keychain tab. -->
                    <div class="skins-tabpanel" data-mpanel="keychain" style="display:none">
                        <div class="skins-slot-row">
                            <button type="button" class="skins-slot skins-slot-keychain" data-keychain-slot title="<?= View::e(Lang::t('skins.keychain')) ?>">
                                <input type="hidden" name="keychain" id="skinsKeychainInput" value="0">
                                <input type="hidden" name="keychain_pos" id="skinsKeychainPosInput" value="0;0;0">
                                <span class="skins-slot-img"></span>
                                <span class="skins-slot-clear" data-keychain-clear title="<?= View::e(Lang::t('skins.clear_slot')) ?>">&times;</span>
                            </button>
                        </div>
                        <div class="skins-place" id="skinsKeychainPlaceBar" hidden>
                            <p class="hint"><?= View::e(Lang::t('skins.keychain_place_hint')) ?></p>
                            <div class="skins-place-row">
                                <button type="button" class="btn btn-ghost btn-sm" id="skinsKeychainPlaceReset"><?= View::e(Lang::t('skins.place_default')) ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" id="skinsModalCancel"><?= View::e(Lang::t('skins.cancel')) ?></button>
                <button type="submit" class="btn btn-primary" id="skinsSubmitBtn"><?= View::e(Lang::t('skins.equip_skin')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Sticker / keychain picker overlay. Opened from a slot; its grid is filled
     client-side from #stickerData / #keychainData, with a search box. -->
<div class="modal-overlay" id="skinsPickerOverlay">
    <div class="modal skins-picker-modal">
        <div class="modal-head">
            <h3 id="skinsPickerTitle"><?= View::e(Lang::t('skins.pick_sticker')) ?></h3>
            <button type="button" class="modal-close" id="skinsPickerClose">&times;</button>
        </div>
        <!-- Apply scope (stickers only): put the chosen sticker on just this
             slot, or on all 5 positions at once. -->
        <div class="skins-picker-scope" id="skinsPickerScope" style="display:none">
            <button type="button" class="skins-scope-btn active" data-scope="slot"><?= View::e(Lang::t('skins.this_slot')) ?></button>
            <button type="button" class="skins-scope-btn" data-scope="all"><?= View::e(Lang::t('skins.all_slots')) ?></button>
        </div>
        <div class="skins-picker-search">
            <input type="text" id="skinsPickerSearch" placeholder="<?= View::e(Lang::t('skins.search_placeholder')) ?>" autocomplete="off">
        </div>
        <div class="modal-body">
            <div class="skins-grid skins-picker-grid" id="skinsPickerGrid"></div>
            <p id="skinsPickerEmpty" class="skins-no-results" style="display:none"><?= View::e(Lang::t('skins.no_results')) ?></p>
        </div>
    </div>
</div>

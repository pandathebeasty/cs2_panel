/**
 * WeaponPaints "Skins" loadout editor — client behaviour.
 *
 * Panel-native port of d:/CS2/skins/assets/js/app.js: section switching, the
 * picker modal + `forma` building, team buttons, wear/float/seed sync, StatTrak
 * toggle, client-rendered Music/Pins with pagination + search, and the AJAX
 * save (ajax=1 + X-Requested-With, expects {status:"success"}) followed by a
 * loadout refresh that swaps #skinsContainer / #categoryNav. Selectors point at
 * the panel markup; UI strings come from window.SKINS_I18N.
 */
(function () {
    "use strict";

    var I18N = window.SKINS_I18N || {};
    function t(key, params) {
        var s = I18N[key] || key;
        if (params) {
            Object.keys(params).forEach(function (k) {
                s = s.replace("{" + k + "}", params[k]);
            });
        }
        return s;
    }

    var root = document.querySelector("[data-skins-root]");
    var skinsContainer = document.getElementById("skinsContainer");
    var categoryNav = document.getElementById("categoryNav");
    var searchInput = document.getElementById("skinsSearchInput");
    if (!root || !skinsContainer) return;

    var BASE = window.CS2_BASE || "";
    var PAGE_SIZE = 48;
    var musicCurrentPage = 1;
    var pinsCurrentPage = 1;

                                                                             
    // Set titles lazily through delegation, including dynamically created cards.
    document.addEventListener("mouseover", function (e) {
        var card = e.target.closest && e.target.closest(".skins-card");
        if (!card || card.dataset.titleSet === "1") return;
        var nameEl = card.querySelector(".skins-card-name");
        var subEl = card.querySelector(".skins-card-sub");
        var name = nameEl ? nameEl.textContent.trim() : "";
        var sub = subEl ? subEl.textContent.trim() : "";
        var full = sub && sub !== name ? (name + " | " + sub) : name;
        if (full) card.setAttribute("title", full);
        card.dataset.titleSet = "1";
    });

    // null = overview; otherwise a weapon defindex / "knife-X" / "glove-X" / "agents"/"music"/"pins".
    var activeDefindex = null;

    function escapeHtml(str) {
        return String(str == null ? "" : str)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }
    // For values placed inside SINGLE-quoted HTML attributes (e.g. JSON blobs).
    function escapeAttr(str) {
        return String(str == null ? "" : str)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/'/g, "&#39;");
    }
    // Short wear label — mirrors WeaponPaintsData::wearLabelShort() thresholds.
    function wearShortLabel(wear) {
        if (isNaN(wear)) return "";
        if (wear <= 0.07) return "FN";
        if (wear <= 0.15) return "MW";
        if (wear <= 0.38) return "FT";
        if (wear < 0.45) return "WW";
        return "BS";
    }

                                              
    function applyDefaultCategoryState() {
        var groups = categoryNav ? categoryNav.querySelectorAll(".skins-cat-group") : [];
        groups.forEach(function (group) {
            var list = group.querySelector(".skins-cat-list");
            if (!list) return;
            list.classList.add("collapsed");
        });
    }
    applyDefaultCategoryState();

    document.addEventListener("click", function (e) {
        var toggle = e.target.closest(".skins-cat-toggle");
        if (!toggle || !categoryNav || !categoryNav.contains(toggle)) return;
        var list = toggle.nextElementSibling;
        if (list) list.classList.toggle("collapsed");
    });

                                     
    function updateTopTabs(activeTab) {
        document.querySelectorAll("#skinsTabsNav .skins-tab").forEach(function (btn) {
            btn.classList.toggle("active", btn.dataset.topTab === activeTab);
        });
    }
    var tabsNav = document.getElementById("skinsTabsNav");
    if (tabsNav) {
        tabsNav.addEventListener("click", function (e) {
            var btn = e.target.closest("[data-top-tab]");
            if (!btn) return;
            var tab = btn.dataset.topTab;
            showWeapon(tab === "skins" ? null : tab);
        });
    }

                                                    
    function renderPaginationControls(containerId, currentPage, totalPages, totalItems, onPageChange) {
        var container = document.getElementById(containerId);
        if (!container) return;
        if (totalPages <= 1) {
            container.innerHTML = '<span class="skins-page-info">' + t("showing_items", { n: totalItems }) + "</span>";
            return;
        }
        var start = (currentPage - 1) * PAGE_SIZE + 1;
        var end = Math.min(currentPage * PAGE_SIZE, totalItems);
        var pagesHtml = [];
        var maxDisplay = 5;
        var startPage = Math.max(1, currentPage - 2);
        var endPage = Math.min(totalPages, startPage + maxDisplay - 1);
        if (endPage - startPage < maxDisplay - 1) startPage = Math.max(1, endPage - maxDisplay + 1);
        for (var p = startPage; p <= endPage; p++) {
            pagesHtml.push('<button type="button" data-page="' + p + '" class="skins-page-btn' + (p === currentPage ? " active" : "") + '">' + p + "</button>");
        }
        var atFirst = currentPage === 1;
        var atLast = currentPage === totalPages;
        container.innerHTML =
            '<span class="skins-page-info">' + escapeHtml(start + "-" + end + " / " + totalItems) + "</span>" +
            '<div class="skins-page-nav">' +
            '<button type="button" data-page="1" ' + (atFirst ? "disabled" : "") + ' class="skins-page-btn" title="' + escapeHtml(t("first")) + '">«</button>' +
            '<button type="button" data-page="' + (currentPage - 1) + '" ' + (atFirst ? "disabled" : "") + ' class="skins-page-btn">‹ ' + escapeHtml(t("prev")) + "</button>" +
            pagesHtml.join("") +
            '<button type="button" data-page="' + (currentPage + 1) + '" ' + (atLast ? "disabled" : "") + ' class="skins-page-btn">' + escapeHtml(t("next")) + " ›</button>" +
            '<button type="button" data-page="' + totalPages + '" ' + (atLast ? "disabled" : "") + ' class="skins-page-btn" title="' + escapeHtml(t("last")) + '">»</button>' +
            "</div>";
        container.querySelectorAll("[data-page]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var target = parseInt(btn.dataset.page, 10);
                if (target >= 1 && target <= totalPages && target !== currentPage) onPageChange(target);
            });
        });
    }

    function parseJsonEl(id, fallback) {
        var el = document.getElementById(id);
        if (!el) return fallback;
        try { return JSON.parse(el.textContent || "null") || fallback; } catch (e) { return fallback; }
    }

    function renderCollectionPage(opts) {
        var container = document.getElementById(opts.containerId);
        if (!container) return;
        var all = parseJsonEl(opts.dataId, []);
        var selected = parseJsonEl(opts.eqId, {});
        var term = (opts.filterTerm || "").trim().toLowerCase();
        var filtered = all.filter(function (m) {
            if (!term) return true;
            return (String(m.name || "").toLowerCase().indexOf(term) !== -1) || (String(m.id || "").indexOf(term) !== -1);
        });
        var totalPages = Math.ceil(filtered.length / PAGE_SIZE) || 1;
        var validPage = Math.min(Math.max(1, opts.page), totalPages);
        var sliced = filtered.slice((validPage - 1) * PAGE_SIZE, validPage * PAGE_SIZE);

        container.innerHTML = sliced.map(function (item) {
            var itemId = parseInt(item.id, 10);
            var equippedTeams = [];
            [1, 2, 3].forEach(function (team) {
                if (selected[team] === itemId || selected[String(team)] === itemId) equippedTeams.push(team);
            });
            var isEq = equippedTeams.length > 0;
            var badges = isEq
                ? '<div class="skins-card-badges">' + equippedTeams.map(function (team) {
                    var lbl = team === 3 ? "CT" : (team === 2 ? "T" : "ALL");
                    var cls = team === 3 ? "skins-badge-ct" : (team === 2 ? "skins-badge-t" : "skins-badge-both");
                    return '<span class="skins-eq-badge ' + cls + '">' + lbl + "</span>";
                }).join("") + "</div>"
                : "";
            var rarity = (item.color ? ' data-rarity style="--rarity:' + escapeAttr(item.color) + '"' : "");
            return '<button type="button" class="skins-card' + (isEq ? " is-equipped" : "") + '"' + rarity + ' ' +
                "data-" + opts.cardAttr + ' data-' + opts.idAttr + '="' + itemId + '" ' +
                'data-name="' + escapeHtml(item.name) + '" data-image="' + escapeHtml(item.image) + '" ' +
                'data-search="' + escapeHtml((opts.searchPrefix + " " + item.name).toLowerCase()) + '" ' +
                'data-team="' + (isEq ? equippedTeams[0] : 1) + '">' +
                badges +
                '<div class="skins-card-img"><img src="' + escapeHtml(item.image) + '" alt="" loading="lazy"></div>' +
                '<p class="skins-card-name">' + escapeHtml(item.name) + "</p>" +
                "</button>";
        }).join("");

        renderPaginationControls(opts.pagId, validPage, totalPages, filtered.length, function (newPage) {
            opts.page = newPage;
            if (opts.containerId === "musicContainer") musicCurrentPage = newPage; else pinsCurrentPage = newPage;
            renderCollectionPage(opts);
            container.scrollIntoView({ behavior: "smooth", block: "start" });
        });
    }

    function renderMusicPage(page, term) {
        musicCurrentPage = page;
        renderCollectionPage({ containerId: "musicContainer", pagId: "musicPagination", dataId: "musicData", eqId: "userMusicEquipped", cardAttr: "music-card", idAttr: "music-id", searchPrefix: "music", page: page, filterTerm: term });
    }
    function renderPinsPage(page, term) {
        pinsCurrentPage = page;
        renderCollectionPage({ containerId: "pinsContainer", pagId: "pinsPagination", dataId: "pinsData", eqId: "userPinsEquipped", cardAttr: "pin-card", idAttr: "pin-id", searchPrefix: "pin coin medal", page: page, filterTerm: term });
    }

                                              
    // The weapon paint grids (~2000 skins total) are NOT rendered server-side —
    // that DOM crashes low-memory mobile browsers. We build a weapon's grid from
    // the JSON catalog the first time it is opened, then cache it in the DOM.
    var weaponCatalog = null;
    function getWeaponCatalog() {
        if (weaponCatalog === null) {
            var el = document.getElementById("weaponCatalog");
            try { weaponCatalog = el ? JSON.parse(el.textContent || "{}") : {}; }
            catch (e) { weaponCatalog = {}; }
        }
        return weaponCatalog;
    }
    function teamBadge(team) {
        var cls = team === 2 ? "skins-badge-t" : (team === 3 ? "skins-badge-ct" : "skins-badge-both");
        var label = team === 2 ? "T" : (team === 3 ? "CT" : "ALL");
        return '<span class="skins-eq-badge ' + cls + '">' + label + "</span>";
    }
                                                                                 
    // Map sticker/keychain coordinates from [-1, 1] to the 4-96% overlay box.
    function cardStickerOverlay(stickers, keychain) {
        // Stickers: row across the top. Keychain: bottom-left corner thumbnail.
        var out = "", row = "";
        (stickers || []).forEach(function (st) {
            var id = parseInt(st && (st.id !== undefined ? st.id : st), 10) || 0;
            if (id <= 0) return;
            var item = findItem(stickerCatalog(), id);
            if (!item || !item.image) return;
            row += '<img src="' + escapeHtml(item.image) + '" alt="" loading="lazy">';
        });
        if (row) out += '<span class="skins-card-stickers">' + row + "</span>";
        var kid = parseInt(keychain && (keychain.id !== undefined ? keychain.id : keychain), 10) || 0;
        if (kid > 0) {
            var kItem = findItem(keychainCatalog(), kid);
            if (kItem && kItem.image) out += '<img class="skins-card-keychain" src="' + escapeHtml(kItem.image) + '" alt="" loading="lazy">';
        }
        return out;
    }

    function buildWeaponGrid(defindex) {
        var grid = skinsContainer.querySelector('[data-lazy-grid][data-defindex="' + CSS.escape(String(defindex)) + '"]');
        if (!grid || grid.dataset.built === "1") return;
        var w = getWeaponCatalog()[String(defindex)];
        if (!w) { grid.dataset.built = "1"; return; }
        var forced = w.forcedTeam ? Number(w.forcedTeam) : "";
        var html = "";
        for (var i = 0; i < w.paints.length; i++) {
            var p = w.paints[i];
            var isCur = p.teams && p.teams.length > 0;
            var dataTeam = isCur ? p.teams[0] : (forced || 1);
            var badges = "";
            if (isCur) {
                for (var j = 0; j < p.teams.length; j++) badges += teamBadge(p.teams[j]);
                badges = '<div class="skins-card-badges">' + badges + "</div>";
            }
            var wear = "";
            if (isCur && p.wearShort) {
                wear = '<span class="skins-wear">' + (p.stattrak ? "ST\u2122 " : "") + escapeHtml(p.wearShort) + "</span>";
            }
            var nametag = (isCur && p.nametag) ? '<span class="skins-nametag">"' + escapeHtml(p.nametag) + '"</span>' : "";
            var rarity = (p.color ? ' data-rarity style="--rarity:' + escapeAttr(p.color) + '"' : "");
            html += '<button type="button" class="skins-card' + (isCur ? " is-equipped" : "") + '" data-skin-card' + rarity
                + ' data-defindex="' + escapeHtml(String(defindex)) + '" data-paintid="' + escapeHtml(String(p.paint)) + '"'
                + ' data-weapon-name="' + escapeHtml(p.name) + '" data-paint-name="' + escapeHtml(p.fullName) + '"'
                + ' data-image="' + escapeHtml(p.image) + '"'
                + ' data-search="' + escapeHtml((p.name + " " + p.fullName).toLowerCase()) + '"'
                + ' data-wear="' + escapeHtml(String(p.wear)) + '" data-seed="' + escapeHtml(String(p.seed)) + '"'
                + ' data-stattrak="' + escapeHtml(String(p.stattrak)) + '" data-stattrak-count="' + escapeHtml(String(p.stCount)) + '"'
                + ' data-nametag="' + escapeHtml(p.nametag) + '" data-team="' + dataTeam + '" data-forced-team="' + forced + '"'
                + ' data-model="' + escapeHtml(w.model || "") + '" data-legacy="' + (p.lg ? "1" : "0") + '"'
                + " data-stickers='" + escapeAttr(JSON.stringify(p.stickers || [])) + "' data-keychain='" + escapeAttr(JSON.stringify(p.keychain || { id: 0 })) + "'>"
                + badges
                + '<div class="skins-card-img"><img src="' + escapeHtml(p.image) + '" alt="" loading="lazy" decoding="async">' + wear + (isCur ? cardStickerOverlay(p.stickers, p.keychain) : "") + "</div>"
                + '<p class="skins-card-name">' + escapeHtml(p.name) + "</p>"
                + '<p class="skins-card-sub">' + escapeHtml(p.paintName) + "</p>"
                + nametag
                + "</button>";
        }
        grid.innerHTML = html;
        grid.dataset.built = "1";
    }

                                              
    function showWeapon(defindex, opts) {
        opts = opts || {};
        var scroll = opts.scroll !== false;
        activeDefindex = (defindex === null || defindex === undefined || defindex === "") ? null : String(defindex);

                                                               
        if (activeDefindex !== null && /^\d+$/.test(activeDefindex)) {
            buildWeaponGrid(activeDefindex);
        }

        var overview = document.getElementById("skinsOverviewSection");
        var sections = skinsContainer.querySelectorAll("[data-weapon-section]");
        // Use an explicit "block" (not "") to SHOW: the sections are hidden by
        // default in CSS (.skins-section[data-weapon-section]{display:none}),
        // and setting style.display="" would fall back to that CSS rule, i.e.
        // stay hidden. "block" overrides it so the active section appears.
        if (overview) overview.style.display = activeDefindex === null ? "block" : "none";
        sections.forEach(function (section) {
            section.style.display = section.dataset.defindex === activeDefindex ? "block" : "none";
        });

        if (activeDefindex === "agents" || activeDefindex === "music" || activeDefindex === "pins") {
            updateTopTabs(activeDefindex);
            var term = searchInput ? searchInput.value : "";
            if (activeDefindex === "music") renderMusicPage(1, term);
            if (activeDefindex === "pins") renderPinsPage(1, term);
        } else {
            updateTopTabs("skins");
        }

        // The weapons sidebar only makes sense for weapon/knife/glove/loadout
        // views. Agents, Music Kits and Pins are not weapons, so hide the
        // left category list for them and let the content use the full width.
        var layout = root.querySelector(".skins-layout");
        if (layout) {
            var hideSidebar = activeDefindex === "agents" || activeDefindex === "music" || activeDefindex === "pins";
            layout.classList.toggle("skins-hide-sidebar", hideSidebar);
        }

        // Active highlight for the per-weapon nav buttons...
        document.querySelectorAll(".skins-nav-btn").forEach(function (btn) {
            btn.classList.toggle("active", btn.dataset.defindex === activeDefindex);
        });
        // ...and for the "Your loadout" / "All weapons" shortcut buttons. The
        // all-weapons button has data-defindex="" which maps to activeDefindex
        // null (the default overview).
        document.querySelectorAll(".skins-nav-overview").forEach(function (btn) {
            var target = btn.dataset.defindex === "" ? null : btn.dataset.defindex;
            btn.classList.toggle("active", target === activeDefindex);
        });

        applySearchFilter();
        if (scroll) window.scrollTo({ top: 0, behavior: "smooth" });
    }

    document.addEventListener("click", function (e) {
        var navBtn = e.target.closest("[data-weapon-nav]");
        if (navBtn && root.contains(navBtn)) showWeapon(navBtn.dataset.defindex);
    });

                                   
    function applySearchFilter() {
        var term = (searchInput ? searchInput.value : "").trim().toLowerCase();

        if (activeDefindex === "music") { renderMusicPage(1, term); return; }
        if (activeDefindex === "pins") { renderPinsPage(1, term); return; }

        // Sidebar nav filtering
        document.querySelectorAll(".skins-nav-btn").forEach(function (btn) {
            var match = !term || (btn.dataset.search || "").indexOf(term) !== -1;
            btn.style.display = match ? "" : "none";
        });
        document.querySelectorAll(".skins-cat-group").forEach(function (group) {
            var any = Array.prototype.some.call(group.querySelectorAll(".skins-nav-btn"), function (btn) {
                return btn.style.display !== "none";
            });
            group.style.display = any ? "" : "none";
        });

        var noResults = document.getElementById("skinsNoResults");
        // Which section is on screen is driven by activeDefindex, NOT by probing
        // inline style.display: on the initial overview render showWeapon() has
        // not run yet, so the weapon sections carry no inline style (only the
        // CSS display:none rule) and a style-based probe wrongly treats the first
        // weapon section as active, leaving the overview grid unfiltered.
        var active = null;
        if (activeDefindex !== null) {
            var sections = skinsContainer.querySelectorAll("[data-weapon-section]");
            active = Array.prototype.find.call(sections, function (s) { return s.dataset.defindex === activeDefindex; });
        }

        if (active) {
            var cards = active.querySelectorAll(".skins-card");
            var visible = 0;
            cards.forEach(function (card) {
                var str = (card.dataset.search || card.textContent || "").toLowerCase();
                var match = !term || str.indexOf(term) !== -1;
                card.style.display = match ? "" : "none";
                if (match) visible++;
            });
            if (noResults) noResults.style.display = visible === 0 ? "" : "none";
        } else {
            var overview = document.getElementById("skinsOverviewSection");
            var ocards = overview ? overview.querySelectorAll(".skins-card") : [];
            ocards.forEach(function (card) {
                var name = (card.querySelector(".skins-card-name") ? card.querySelector(".skins-card-name").textContent : "").toLowerCase();
                var sub = (card.querySelector(".skins-card-sub") ? card.querySelector(".skins-card-sub").textContent : "").toLowerCase();
                var match = !term || name.indexOf(term) !== -1 || sub.indexOf(term) !== -1;
                card.style.display = match ? "" : "none";
            });
            if (noResults) noResults.style.display = "none";
        }
    }
    if (searchInput) searchInput.addEventListener("input", applySearchFilter);

                                   
    function showToast(message, variant) {
        var layer = document.getElementById("skinsToastLayer");
        if (!layer) {
            layer = document.createElement("div");
            layer.id = "skinsToastLayer";
            layer.className = "skins-toast-layer";
            document.body.appendChild(layer);
        }
        var el = document.createElement("div");
        el.className = "skins-toast" + (variant === "error" ? " error" : "");
        el.textContent = message;
        layer.appendChild(el);
        requestAnimationFrame(function () { el.classList.add("visible"); });
        setTimeout(function () {
            el.classList.remove("visible");
            setTimeout(function () { el.remove(); }, 220);
        }, 2800);
    }

                                  
    var modal = document.getElementById("skinsModal");
    var modalImage = document.getElementById("skinsModalImage");
    var modalThumb = document.getElementById("skinsModalThumb");
    var modalWeaponName = document.getElementById("skinsModalWeaponName");
    var modalPaintName = document.getElementById("skinsModalPaintName");
    var formaInput = document.getElementById("skinsFormaInput");
    var teamInput = document.getElementById("skinsTeamInput");
    var teamButtons = document.querySelectorAll(".skins-team-btn");
    var teamButtonsWrap = document.getElementById("skinsTeamButtons");
    var teamNote = document.getElementById("skinsTeamForcedNote");
    var skinOnlyFields = document.getElementById("skinsOnlyFields");
    var wearSelect = document.getElementById("skinsWearSelect");
    var wearInput = document.getElementById("skinsWearInput");
    var seedInput = document.getElementById("skinsSeedInput");
    var nametagInput = document.getElementById("skinsNametagInput");
    var stattrakWrap = document.getElementById("skinsStattrakWrap");
    var stattrakToggle = document.getElementById("skinsStattrakToggle");
    var stattrakCountWrap = document.getElementById("skinsStattrakCountWrap");
    var stattrakCountInput = document.getElementById("skinsStattrakCountInput");
    var configForm = document.getElementById("skinsConfigForm");
    var submitBtn = document.getElementById("skinsSubmitBtn");
    var submitBtnDefault = submitBtn ? submitBtn.textContent : "";

    var TEAM_LABELS = { 2: t("terrorists"), 3: t("counter_terrorists") };

    function setActiveTeam(team) {
        teamInput.value = team;
        teamButtons.forEach(function (btn) { btn.classList.toggle("active", btn.dataset.team === String(team)); });
    }
    teamButtons.forEach(function (btn) {
        btn.addEventListener("click", function () { setActiveTeam(btn.dataset.team); });
    });

    function applyForcedTeam(forcedTeam) {
        if (forcedTeam) {
            if (teamButtonsWrap) teamButtonsWrap.style.display = "none";
            if (teamNote) {
                teamNote.style.display = "";
                teamNote.textContent = t("forced_team_note", { team: TEAM_LABELS[forcedTeam] || "" });
            }
            setActiveTeam(forcedTeam);
        } else {
            if (teamButtonsWrap) teamButtonsWrap.style.display = "";
            if (teamNote) teamNote.style.display = "none";
        }
    }

    function closestWearOption(wear) {
        var options = [0, 0.07, 0.15, 0.38, 0.45];
        var closest = 0, smallest = Infinity;
        options.forEach(function (opt) {
            var diff = Math.abs(opt - wear);
            if (diff < smallest) { smallest = diff; closest = opt; }
        });
        return closest.toFixed(2);
    }

    if (document.getElementById("skinsFloatPresets")) {
        document.getElementById("skinsFloatPresets").addEventListener("click", function (e) {
            var btn = e.target.closest("[data-preset]");
            if (!btn) return;
            if (wearInput) wearInput.value = btn.dataset.preset;
            if (wearSelect) wearSelect.value = closestWearOption(parseFloat(btn.dataset.preset));
        });
    }
    if (wearSelect) {
        wearSelect.addEventListener("change", function () { if (wearInput) wearInput.value = wearSelect.value; });
    }
    if (stattrakToggle) {
        stattrakToggle.addEventListener("change", function () {
            if (stattrakCountWrap) stattrakCountWrap.style.display = stattrakToggle.checked ? "" : "none";
        });
    }

    function openModal(cfg) {
        modalImage.src = cfg.image || "";
        modalImage.alt = cfg.paintName || "";
        modalWeaponName.textContent = cfg.weaponName || "";
        modalPaintName.textContent = cfg.paintName || "";
        formaInput.value = cfg.forma;
        if (nametagInput) nametagInput.value = cfg.nametag || "";

        applyForcedTeam(cfg.forcedTeam ? Number(cfg.forcedTeam) : null);
        if (!cfg.forcedTeam) setActiveTeam(cfg.team || 1);

        if (!cfg.hasPaintFields) {
            if (skinOnlyFields) skinOnlyFields.style.display = "none";
            // Non-paint items (agents/music/pins): no sticker/keychain tabs.
            if (tabBtnStickers) tabBtnStickers.style.display = "none";
            if (tabBtnKeychain) tabBtnKeychain.style.display = "none";
        } else {
            if (skinOnlyFields) skinOnlyFields.style.display = "";
            var w = cfg.wear !== undefined ? parseFloat(cfg.wear) : 0;
            var s = cfg.seed !== undefined ? parseInt(cfg.seed, 10) : 0;
            if (wearInput) wearInput.value = isNaN(w) ? "0" : w;
            if (seedInput) seedInput.value = isNaN(s) ? "0" : s;
            if (wearSelect) wearSelect.value = closestWearOption(isNaN(w) ? 0 : w);

            // Gloves can't be renamed or get StatTrak in CS2 — hide both fields.
            var nametagField = document.getElementById("skinsNametagField");
            if (nametagField) nametagField.style.display = cfg.isGlove ? "none" : "";
            if (cfg.isGlove && nametagInput) nametagInput.value = "";

            if (stattrakWrap) {
                if (cfg.isGlove) {
                    stattrakWrap.style.display = "none";
                } else {
                    stattrakWrap.style.display = "";
                    var isSt = String(cfg.stattrak) === "1";
                    if (stattrakToggle) stattrakToggle.checked = isSt;
                    if (stattrakCountWrap) stattrakCountWrap.style.display = isSt ? "" : "none";
                    if (stattrakCountInput) stattrakCountInput.value = cfg.stattrakCount || 0;
                }
            }

            // Stickers + keychain: only for real weapon skins (not knives/gloves)
            // and only when the DB supports it. Populate the 5 slots + keychain.
            fillStickerSlots(cfg);
        }
        setModalTab("skin"); // always start on the Skin tab

        modal.classList.add("open");
        start3d(cfg);
    }

                                                                                    
    var modalTabs = document.getElementById("skinsModalTabs");
    var tabBtnStickers = document.getElementById("skinsTabBtnStickers");
    var tabBtnKeychain = document.getElementById("skinsTabBtnKeychain");
    var stickerSlotsRow = document.getElementById("skinsStickerSlots");
    var STICKERS = null, KEYCHAINS = null;
    // In-modal working state: 5 sticker slot ids + keychain id. Sticker
    // offsets (x, y, rotation) stay 0 = game default unless placed in 3D.
    var slotState = [0, 0, 0, 0, 0];
    var posState = [0, 0, 0, 0, 0].map(function () { return { x: 0, y: 0, r: 0 }; });
    var kcState = 0;
    var kcPos = { x: 0, y: 0, z: 0 };

    function stickerCatalog() {
        if (STICKERS === null) {
            var el = document.getElementById("stickerData");
            try { STICKERS = el ? JSON.parse(el.textContent || "[]") : []; } catch (e) { STICKERS = []; }
        }
        return STICKERS;
    }
    function keychainCatalog() {
        if (KEYCHAINS === null) {
            var el = document.getElementById("keychainData");
            try { KEYCHAINS = el ? JSON.parse(el.textContent || "[]") : []; } catch (e) { KEYCHAINS = []; }
        }
        return KEYCHAINS;
    }
    // Index by id for O(1) lookup (avoids scanning the whole list per paint).
    var stickerById = null, keychainById = null;
    function indexOf(list) { var m = {}; for (var i = 0; i < list.length; i++) m[String(list[i].id)] = list[i]; return m; }
    function findItem(list, id) {
        id = String(id);
        if (list === stickerCatalog()) { if (!stickerById) stickerById = indexOf(list); return stickerById[id] || null; }
        if (list === keychainCatalog()) { if (!keychainById) keychainById = indexOf(list); return keychainById[id] || null; }
        for (var i = 0; i < list.length; i++) { if (String(list[i].id) === id) return list[i]; }
        return null;
    }

    function paintStickerSlot(idx) {
        var slot = stickerSlotsRow ? stickerSlotsRow.querySelector('[data-sticker-slot="' + idx + '"]') : null;
        if (!slot) return;
        var id = slotState[idx] || 0;
        var idIn = slot.querySelector('input[name="stickers[' + idx + ']"]');
        if (idIn) idIn.value = id;
        writeStickerPos(idx);
        var imgWrap = slot.querySelector(".skins-slot-img");
        var item = id > 0 ? findItem(stickerCatalog(), id) : null;
        if (item && item.image) {
            imgWrap.innerHTML = '<img src="' + escapeHtml(item.image) + '" alt="" loading="lazy">';
            slot.classList.add("filled"); slot.title = item.name || "";
        } else { imgWrap.innerHTML = ""; slot.classList.remove("filled"); }
    }
    function paintKeychainSlot() {
        var slot = document.querySelector("[data-keychain-slot]");
        if (!slot) return;
        var idIn = document.getElementById("skinsKeychainInput");
        if (idIn) idIn.value = kcState || 0;
        var imgWrap = slot.querySelector(".skins-slot-img");
        var item = kcState > 0 ? findItem(keychainCatalog(), kcState) : null;
        if (item && item.image) {
            imgWrap.innerHTML = '<img src="' + escapeHtml(item.image) + '" alt="" loading="lazy">';
            slot.classList.add("filled"); slot.title = item.name || "";
        } else { imgWrap.innerHTML = ""; slot.classList.remove("filled"); }
    }

    function fillStickerSlots(cfg) {
        var kind = (cfg.forma || "").split("-")[0];
        var isWeapon = /^\d+$/.test(kind);
        var show = !!window.SKINS_STICKERS_ENABLED && isWeapon && !cfg.isGlove;
        if (tabBtnStickers) tabBtnStickers.style.display = show ? "" : "none";
        if (tabBtnKeychain) tabBtnKeychain.style.display = show ? "" : "none";
        // Reset state from cfg (stickers may be objects {id,..} or bare ids).
        slotState = [0, 0, 0, 0, 0];
        for (var i = 0; i < 5; i++) {
            var s = (cfg.stickers || [])[i];
            slotState[i] = (s && typeof s === "object") ? (parseInt(s.id, 10) || 0) : (parseInt(s, 10) || 0);
            var obj = (s && typeof s === "object") ? s : {};
            posState[i] = { x: parseFloat(obj.x) || 0, y: parseFloat(obj.y) || 0, r: parseFloat(obj.r) || 0 };
        }
        var kc = cfg.keychain;
        kcState = (kc && typeof kc === "object") ? (parseInt(kc.id, 10) || 0) : (parseInt(kc, 10) || 0);
        kcPos = (kc && typeof kc === "object") ? { x: parseFloat(kc.x) || 0, y: parseFloat(kc.y) || 0, z: parseFloat(kc.z) || 0 } : { x: 0, y: 0, z: 0 };
        for (var j = 0; j < 5; j++) paintStickerSlot(j);
        paintKeychainSlot();
        if (!show) setModalTab("skin");
    }

                           
    function setModalTab(name) {
        if (!modalTabs) return;
        currentModalTab = name;
        modalTabs.querySelectorAll(".skins-modal-tab").forEach(function (b) {
            b.classList.toggle("active", b.dataset.mtab === name);
        });
        document.querySelectorAll(".skins-tabpanel").forEach(function (p) {
            p.style.display = p.dataset.mpanel === name ? "" : "none";
        });
        updatePlacement();
    }
    if (modalTabs) {
        modalTabs.addEventListener("click", function (e) {
            var b = e.target.closest(".skins-modal-tab");
            if (b && b.style.display !== "none") setModalTab(b.dataset.mtab);
        });
    }

    // Optional 3D preview (Settings → Skins). The viewer module is loaded on
    // first use; missing models or WebGL2 show 2D, while a missing paint
    // texture leaves the base model available for sticker placement.
    var CFG3D = window.SKINS_3D || null;
    var stage3d = document.getElementById("skins3dStage");
    var canvas3d = document.getElementById("skins3dCanvas");
    var fallback3d = document.getElementById("skins3dFallback");
    var modalBox = modal ? modal.querySelector(".skins-modal") : null;
    var placeBar = document.getElementById("skinsPlaceBar");
    var placeRot = document.getElementById("skinsPlaceRot");
    var placeRotOut = document.getElementById("skinsPlaceRotOut");
    var placeReset = document.getElementById("skinsPlaceReset");
    var currentModalTab = "skin";
    var viewer = null, viewerPromise = null, view3dOn = false, view3dSeq = 0, activeSticker = -1;

    function writeStickerPos(idx) {
        var input = modal ? modal.querySelector('input[name="sticker_pos[' + idx + ']"]') : null;
        var p = posState[idx];
        if (input) input.value = slotState[idx] > 0 ? (p.x + ";" + p.y + ";" + p.r) : "0;0;0";
    }
    function resetStickerPos(idx) { posState[idx] = { x: 0, y: 0, r: 0 }; writeStickerPos(idx); }

    function loadViewer() {
        if (!viewerPromise) {
            viewerPromise = import(CFG3D.module).then(function (m) { viewer = m; return m; })
                .catch(function (err) { viewerPromise = null; if (window.console) console.warn(err); return null; });
        }
        return viewerPromise;
    }
    function stop3d() {
        view3dOn = false;
        activeSticker = -1;
        if (stage3d) stage3d.hidden = true;
        if (modalBox) modalBox.classList.remove("has-3d", "is-3d-loading", "is-3d-2d", "is-3d-notex");
        if (viewer) viewer.hide();
        updatePlacement();
    }
    function show2d(image) {
        stop3d();
        if (!image || !fallback3d || !stage3d || !modalBox) return;
        fallback3d.src = image;
        stage3d.hidden = false;
        modalBox.classList.add("has-3d", "is-3d-2d");
    }
    function start3d(cfg) {
        stop3d();
        var seq = ++view3dSeq;
        if (!CFG3D || !stage3d || !canvas3d || !modalBox || !cfg.model3d || !cfg.paint3d) return;
        var fail = function (err) {
            if (seq !== view3dSeq) return;
            if (err && window.console) console.warn(err);
            show2d(cfg.image);
        };
        stage3d.hidden = false;
        modalBox.classList.add("has-3d", "is-3d-loading");
        loadViewer().then(function (m) {
            if (!m || seq !== view3dSeq) return false;
            return m.show(canvas3d, {
                sources: CFG3D.sources || [], model: cfg.model3d, folder: cfg.folder3d || cfg.model3d,
                paint: cfg.paint3d, legacy: !!cfg.legacy3d, defindex: cfg.defindex3d,
                keychains: CFG3D.keychains || []
            });
        }).then(function (ok) {
            if (seq !== view3dSeq) return;
            if (!ok) { fail(); return; }
            view3dOn = true;
            modalBox.classList.remove("is-3d-loading");
            modalBox.classList.toggle("is-3d-notex", ok === "untextured");
            sync3dStickers();
            sync3dKeychain();
            updatePlacement();
        }).catch(fail);
    }
    function sync3dKeychain() {
        if (!view3dOn || !viewer || !viewer.setKeychain) return;
        var item = kcState > 0 ? findItem(keychainCatalog(), kcState) : null;
        viewer.setKeychain(item ? { id: kcState, image: item.image || "", x: kcPos.x, y: kcPos.y, z: kcPos.z } : null)
            .catch(function (err) { if (window.console) console.warn(err); });
    }
    function sync3dStickers() {
        if (!view3dOn || !viewer) return;
        viewer.setStickers(slotState.map(function (id, i) {
            var item = id > 0 ? findItem(stickerCatalog(), id) : null;
            return { src: item && item.image ? item.image : "", x: posState[i].x, y: posState[i].y, r: posState[i].r };
        }));
    }
    function onStickerPlaced(idx, x, y) {
        if (idx < 0 || !slotState[idx]) return;
        posState[idx].x = x;
        posState[idx].y = y;
        writeStickerPos(idx);
        sync3dStickers();
        updatePlacement();
    }
    function updatePlacement() {
        var on = view3dOn && viewer && viewer.hasStickerLayout() && currentModalTab === "stickers";
        if (activeSticker >= 0 && !slotState[activeSticker]) activeSticker = -1;
        if (placeBar) placeBar.hidden = !on;
        if (stickerSlotsRow) {
            stickerSlotsRow.querySelectorAll("[data-sticker-slot]").forEach(function (s) {
                s.classList.toggle("is-active", on && parseInt(s.dataset.stickerSlot, 10) === activeSticker);
            });
        }
        var a = on ? activeSticker : -1;
        if (placeRot) {
            placeRot.disabled = a < 0;
            placeRot.value = a >= 0 ? posState[a].r : 0;
        }
        if (placeRotOut) placeRotOut.textContent = (a >= 0 ? Math.round(posState[a].r) : 0) + "\u00b0";
        if (placeReset) placeReset.disabled = a < 0 || (!posState[a].x && !posState[a].y && !posState[a].r);
        if (viewer && view3dOn) viewer.setPlacement(a, onStickerPlaced);
    }
    if (placeRot) placeRot.addEventListener("input", function () {
        if (activeSticker < 0) return;
        posState[activeSticker].r = parseFloat(placeRot.value) || 0;
        writeStickerPos(activeSticker);
        sync3dStickers();
        if (placeRotOut) placeRotOut.textContent = Math.round(posState[activeSticker].r) + "\u00b0";
        if (placeReset) placeReset.disabled = false;
    });
    if (placeReset) placeReset.addEventListener("click", function () {
        if (activeSticker < 0) return;
        resetStickerPos(activeSticker);
        sync3dStickers();
        updatePlacement();
    });
    var reset3d = document.getElementById("skins3dReset");
    if (reset3d) reset3d.addEventListener("click", function () { if (viewer) viewer.resetView(); });

                               
    var pickerOverlay = document.getElementById("skinsPickerOverlay");
    var pickerGrid = document.getElementById("skinsPickerGrid");
    var pickerSearch = document.getElementById("skinsPickerSearch");
    var pickerTitle = document.getElementById("skinsPickerTitle");
    var pickerEmpty = document.getElementById("skinsPickerEmpty");
    var pickerMode = null, pickerSlotIndex = null, pickerScope = "slot";
    var pickerScopeWrap = document.getElementById("skinsPickerScope");
    // Cache the fully-built grid HTML per mode so re-opening is instant (the
    // catalogs never change). This is what removes the ~1s open lag: we build
    // the hundreds of cards once, then just toggle visibility + filter.
    // Incremental ("virtualized") rendering — stickers has 10k+ entries, so
    // building them all at once froze the modal. We keep the filtered list in
    // memory and render it in small batches, appending more as you scroll.
    var PICK_BATCH = 80;
    var pickFiltered = [];   // array of catalog items matching the current term
    var pickCursor = 0;      // how many of pickFiltered are rendered so far

    function tileHtml(it) {
        var name = (it.name || "").toString();
        var rarity = (it.color ? ' data-rarity style="--rarity:' + escapeAttr(it.color) + '"' : "");
        return '<button type="button" class="skins-card" data-pick-id="' + escapeHtml(String(it.id)) + '"' + rarity + '>'
            + '<div class="skins-card-img"><img src="' + escapeHtml(it.image || "") + '" alt="" loading="lazy" decoding="async"></div>'
            + '<p class="skins-card-name">' + escapeHtml(name) + "</p></button>";
    }
    function renderNextBatch() {
        if (!pickGridInner || pickCursor >= pickFiltered.length) return;
        var end = Math.min(pickCursor + PICK_BATCH, pickFiltered.length);
        var html = "";
        for (var i = pickCursor; i < end; i++) { html += tileHtml(pickFiltered[i]); }
        pickGridInner.insertAdjacentHTML("beforeend", html);
        pickCursor = end;
    }
    // The grid gets a stable "none" tile + an inner container we append batches
    // into (so we never rebuild the whole 10k list).
    var pickGridInner = null;
    function resetPickerGrid(withNone) {
        pickGridInner = document.createElement("div");
        pickGridInner.style.display = "contents";
        pickGrid_setContent(withNone ? ('<button type="button" class="skins-card skins-picker-none" data-pick-id="0"><div class="skins-card-img"></div><p class="skins-card-name">' + escapeHtml(t("not_set")) + "</p></button>") : "");
        pickerGrid.appendChild(pickGridInner);
    }
    function pickGrid_setContent(html) { pickerGrid.innerHTML = html; }

    function applyFilter(term) {
        term = (term || "").trim().toLowerCase();
        var list = pickerMode === "keychain" ? keychainCatalog() : stickerCatalog();
        if (!term) { pickFiltered = list; }
        else {
            pickFiltered = [];
            for (var i = 0; i < list.length; i++) {
                if ((list[i].name || "").toString().toLowerCase().indexOf(term) !== -1) pickFiltered.push(list[i]);
            }
        }
        pickCursor = 0;
        // "none" tile only when not searching (clear-the-slot shortcut).
        resetPickerGrid(!term);
        renderNextBatch();
        if (pickerEmpty) pickerEmpty.style.display = (pickFiltered.length === 0 && term) ? "" : "none";
        if (pickerGrid) pickerGrid.scrollTop = 0;
    }

    function openPicker(mode, slotIndex) {
        pickerMode = mode; pickerSlotIndex = slotIndex;
        if (pickerTitle) pickerTitle.textContent = t(mode === "keychain" ? "pick_keychain" : "pick_sticker");
        pickerScope = "slot";
        if (pickerScopeWrap) {
            pickerScopeWrap.style.display = mode === "sticker" ? "" : "none";
            pickerScopeWrap.querySelectorAll(".skins-scope-btn").forEach(function (b) {
                b.classList.toggle("active", b.dataset.scope === "slot");
            });
        }
        if (pickerSearch) pickerSearch.value = "";
        if (pickerOverlay) pickerOverlay.classList.add("open");
                                                                                    
        requestAnimationFrame(function () { applyFilter(""); });
        if (pickerSearch) setTimeout(function () { pickerSearch.focus(); }, 50);
    }
    function closePicker() { if (pickerOverlay) pickerOverlay.classList.remove("open"); }

    // Append the next batch as the user scrolls near the bottom.
    if (pickerGrid) {
        pickerGrid.addEventListener("scroll", function () {
            if (pickerGrid.scrollTop + pickerGrid.clientHeight >= pickerGrid.scrollHeight - 120) renderNextBatch();
        });
    }
    // Debounced search so typing stays smooth over 10k items.
    var pickSearchTimer = null;
    if (pickerSearch) pickerSearch.addEventListener("input", function () {
        clearTimeout(pickSearchTimer);
        var v = pickerSearch.value;
        pickSearchTimer = setTimeout(function () { applyFilter(v); }, 120);
    });
    if (pickerScopeWrap) {
        pickerScopeWrap.addEventListener("click", function (e) {
            var b = e.target.closest(".skins-scope-btn");
            if (!b) return;
            pickerScope = b.dataset.scope === "all" ? "all" : "slot";
            pickerScopeWrap.querySelectorAll(".skins-scope-btn").forEach(function (x) { x.classList.toggle("active", x === b); });
        });
    }
    if (pickerOverlay) {
        // Intentionally NO backdrop-click close: the picker closes only via the
        // × button (or Escape), so a stray click next to it won't lose your work.
        var pc = document.getElementById("skinsPickerClose");
        if (pc) pc.addEventListener("click", closePicker);
        pickerGrid.addEventListener("click", function (e) {
            var tile = e.target.closest("[data-pick-id]");
            if (!tile) return;
            var id = parseInt(tile.getAttribute("data-pick-id"), 10) || 0;
            if (pickerMode === "keychain") {
                if (kcState !== id) kcPos = { x: 0, y: 0, z: 0 };
                kcState = id; paintKeychainSlot(); sync3dKeychain();
            } else if (pickerScope === "all") {
                                                            
                for (var i = 0; i < 5; i++) { slotState[i] = id; resetStickerPos(i); paintStickerSlot(i); }
            } else if (pickerSlotIndex !== null) {
                if (slotState[pickerSlotIndex] !== id) resetStickerPos(pickerSlotIndex);
                slotState[pickerSlotIndex] = id; paintStickerSlot(pickerSlotIndex);
                if (view3dOn && id > 0) activeSticker = pickerSlotIndex;
            }
            sync3dStickers();
            updatePlacement();
            closePicker();
        });
    }

    // Slot clicks (delegated on the modal body): open picker; × clears.
    if (modal) {
        modal.addEventListener("click", function (e) {
            var clearS = e.target.closest("[data-sticker-clear]");
            if (clearS) {
                e.stopPropagation();
                var idx = parseInt(clearS.getAttribute("data-sticker-clear"), 10);
                slotState[idx] = 0; resetStickerPos(idx); paintStickerSlot(idx);
                sync3dStickers(); updatePlacement();
                return;
            }
            var clearK = e.target.closest("[data-keychain-clear]");
            if (clearK) { e.stopPropagation(); kcState = 0; kcPos = { x: 0, y: 0, z: 0 }; paintKeychainSlot(); sync3dKeychain(); return; }
            var sSlot = e.target.closest("[data-sticker-slot]");
            if (sSlot) {
                var si = parseInt(sSlot.dataset.stickerSlot, 10);
                // With 3D: the first click on a filled slot selects it for placement,
                // a second click opens the picker.
                if (view3dOn && slotState[si] > 0 && activeSticker !== si) {
                    activeSticker = si;
                    updatePlacement();
                    return;
                }
                openPicker("sticker", si);
                return;
            }
            var kSlot = e.target.closest("[data-keychain-slot]");
            if (kSlot) { openPicker("keychain", null); return; }
        });
    }

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && pickerOverlay && pickerOverlay.classList.contains("open")) closePicker();
    });

    function closeModal() { view3dSeq++; stop3d(); modal.classList.remove("open"); }

    if (modal) {
        // No backdrop-click close — only the × button, Cancel, or Escape close
        // the modal, so a click next to it won't discard the open loadout.
        var mc = document.getElementById("skinsModalClose");
        var mcancel = document.getElementById("skinsModalCancel");
        if (mc) mc.addEventListener("click", closeModal);
        if (mcancel) mcancel.addEventListener("click", closeModal);
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && modal.classList.contains("open")) closeModal();
        });
    }

    // Delegated card clicks -> open modal with the right `forma`
    document.addEventListener("click", function (e) {
        var agentCard = e.target.closest("[data-agent-card]");
        if (agentCard && root.contains(agentCard)) {
            var ateam = agentCard.dataset.team;
            openModal({
                image: agentCard.dataset.image,
                weaponName: ateam === "3" ? t("nav_agents_ct") : t("nav_agents_t"),
                paintName: agentCard.dataset.agentName,
                forma: "agent-" + ateam + "-" + agentCard.dataset.model,
                team: ateam, hasPaintFields: false, forcedTeam: ateam
            });
            return;
        }
        var skinCard = e.target.closest("[data-skin-card]");
        if (skinCard && root.contains(skinCard)) {
            var stks = [], kc = { id: 0, x: 0, y: 0 };
            try { stks = JSON.parse(skinCard.dataset.stickers || "[]"); } catch (ex) { stks = []; }
            try { kc = JSON.parse(skinCard.dataset.keychain || '{"id":0}'); } catch (ex) { kc = { id: 0 }; }
            openModal({
                image: skinCard.dataset.image, weaponName: skinCard.dataset.weaponName, paintName: skinCard.dataset.paintName,
                forma: skinCard.dataset.defindex + "-" + skinCard.dataset.paintid,
                wear: skinCard.dataset.wear, seed: skinCard.dataset.seed, team: skinCard.dataset.team,
                hasPaintFields: true, forcedTeam: skinCard.dataset.forcedTeam,
                stattrak: skinCard.dataset.stattrak, stattrakCount: skinCard.dataset.stattrakCount, nametag: skinCard.dataset.nametag,
                stickers: stks, keychain: kc,
                model3d: skinCard.dataset.model, paint3d: skinCard.dataset.paintid, legacy3d: skinCard.dataset.legacy === "1",
                defindex3d: skinCard.dataset.defindex
            });
            return;
        }
        var knifeCard = e.target.closest("[data-knife-card]");
        if (knifeCard && root.contains(knifeCard)) {
            var kHasPaint = knifeCard.dataset.paintid !== undefined;
            openModal({
                image: knifeCard.dataset.image, weaponName: knifeCard.dataset.weaponName, paintName: knifeCard.dataset.paintName,
                forma: kHasPaint ? "knife-" + knifeCard.dataset.defindex + "-" + knifeCard.dataset.paintid : "knife-" + knifeCard.dataset.defindex,
                wear: knifeCard.dataset.wear, seed: knifeCard.dataset.seed, team: knifeCard.dataset.team,
                hasPaintFields: kHasPaint, stattrak: knifeCard.dataset.stattrak, stattrakCount: knifeCard.dataset.stattrakCount, nametag: knifeCard.dataset.nametag,
                model3d: kHasPaint ? knifeCard.dataset.model : "", paint3d: knifeCard.dataset.paintid, legacy3d: knifeCard.dataset.legacy === "1"
            });
            return;
        }
        var gloveCard = e.target.closest("[data-glove-card]");
        if (gloveCard && root.contains(gloveCard)) {
            var gHasPaint = gloveCard.dataset.paintid !== undefined;
            openModal({
                image: gloveCard.dataset.image, weaponName: gloveCard.dataset.weaponName, paintName: gloveCard.dataset.paintName,
                forma: gHasPaint ? "glove-" + gloveCard.dataset.defindex + "-" + gloveCard.dataset.paintid : "glove-" + gloveCard.dataset.defindex,
                wear: gloveCard.dataset.wear, seed: gloveCard.dataset.seed, team: gloveCard.dataset.team,
                hasPaintFields: gHasPaint, isGlove: true,
                model3d: gHasPaint ? gloveCard.dataset.model : "", paint3d: gloveCard.dataset.paintid
            });
            return;
        }
        var musicCard = e.target.closest("[data-music-card]");
        if (musicCard && root.contains(musicCard)) {
            openModal({
                image: musicCard.dataset.image, weaponName: t("nav_music_kits"), paintName: musicCard.dataset.name,
                forma: "music-" + musicCard.dataset.musicId, team: musicCard.dataset.team || 1, hasPaintFields: false
            });
            return;
        }
        var pinCard = e.target.closest("[data-pin-card]");
        if (pinCard && root.contains(pinCard)) {
            openModal({
                image: pinCard.dataset.image, weaponName: t("nav_pins_coins"), paintName: pinCard.dataset.name,
                forma: "pin-" + pinCard.dataset.pinId, team: pinCard.dataset.team || 1, hasPaintFields: false
            });
        }
    });

                                                
    function setSubmitLoading(loading) {
        if (!submitBtn) return;
        submitBtn.disabled = loading;
        submitBtn.textContent = loading ? t("saving_ellipsis") : submitBtnDefault;
    }

    function refreshLoadoutState() {
        // cache:"no-store" is essential: without it the browser can serve this
        // GET from its HTTP cache and return the PRE-equip HTML, so the just-
        // equipped skin only shows after a hard refresh. Force a fresh fetch.
        return fetch(window.location.pathname + window.location.search, {
            method: "GET", credentials: "same-origin", cache: "no-store",
            headers: { "X-Requested-With": "XMLHttpRequest", "Cache-Control": "no-cache", "Pragma": "no-cache" }
        }).then(function (res) {
            if (!res.ok) throw new Error("Refresh failed");
            return res.text();
        }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, "text/html");
            var newSkins = doc.getElementById("skinsContainer");
            var newNav = doc.getElementById("categoryNav");
            if (!newSkins || !newNav) throw new Error("Unexpected response");

            var openCategories = {};
            if (categoryNav) {
                categoryNav.querySelectorAll(".skins-cat-group").forEach(function (group) {
                    var list = group.querySelector(".skins-cat-list");
                    if (list && !list.classList.contains("collapsed")) openCategories[group.dataset.category] = true;
                });
            }

            ["userMusicEquipped", "userPinsEquipped"].forEach(function (id) {
                var fresh = doc.getElementById(id), old = document.getElementById(id);
                if (fresh && old) old.textContent = fresh.textContent;
            });

            skinsContainer.innerHTML = newSkins.innerHTML;
            if (categoryNav) categoryNav.innerHTML = newNav.innerHTML;
            // The refreshed HTML carries a fresh #weaponCatalog with the updated
            // equipped state; drop the cached copy so lazy grids rebuild from it.
            weaponCatalog = null;

            var hadOpen = Object.keys(openCategories).length > 0;
            if (categoryNav) {
                categoryNav.querySelectorAll(".skins-cat-group").forEach(function (group, idx) {
                    var list = group.querySelector(".skins-cat-list");
                    if (!list) return;
                    var shouldOpen = hadOpen ? !!openCategories[group.dataset.category] : idx === 0;
                    list.classList.toggle("collapsed", !shouldOpen);
                });
            }

            showWeapon(activeDefindex, { scroll: false });
            var term = searchInput ? searchInput.value : "";
            if (activeDefindex === "music") renderMusicPage(musicCurrentPage, term);
            else if (activeDefindex === "pins") renderPinsPage(pinsCurrentPage, term);
        });
    }

    // Optimistic in-place update of the overview card for the weapon that was
    // just equipped, from the modal's current values — so the change shows
    // INSTANTLY, before the background refresh reconciles the exact state
    // (team splits etc.). Only touches the simple weapon/knife/glove cards;
    // music/pins are re-rendered by their own pagination after refresh.
    function patchOverviewCard() {
        try {
            var forma = formaInput ? formaInput.value : "";
            if (!forma) return;
            var parts = forma.split("-");
            var kind = parts[0];
            var navSel = null;
            if (kind === "knife") navSel = '[data-weapon-nav][data-defindex="knife-' + parts[1] + '"]';
            else if (kind === "glove") navSel = '[data-weapon-nav][data-defindex="glove-' + parts[1] + '"]';
            else if (kind !== "agent" && kind !== "music" && kind !== "pin") navSel = '[data-weapon-nav][data-defindex="' + parts[0] + '"]';
            if (!navSel) return;

            var newSrc = (modalImage && modalImage.src) ? modalImage.src : "";
            var newSub = (modalPaintName && modalPaintName.textContent) ? modalPaintName.textContent : "";
            var team = parseInt(teamInput ? teamInput.value : "1", 10) || 1;

            // The same weapon has a card in BOTH the overview (#skinsOverviewSection)
            // and the "Your loadout" view — patch every match, not just the first,
            // so navigating between them after an equip never shows a stale skin.
            var cards = root.querySelectorAll(navSel);
            if (!cards.length) return;
            cards.forEach(function (card) { patchSummaryCard(card, newSrc, newSub, team); });
        } catch (err) { if (window.console) console.error(err); }
    }

                                                                            
    // Keep overview and loadout summaries in sync.
    function patchSummaryCard(card, newSrc, newSub, team) {
        var img = card.querySelector(".skins-card-img img");
        if (img && newSrc) {
            var well = card.querySelector(".skins-card-img");
            var apply = function () {
                img.style.display = "";
                img.removeAttribute("data-fallback-applied");
                if (well) well.classList.remove("skins-img-missing");
                img.src = newSrc;
            };
            var pre = new Image();
            pre.src = newSrc;
            if (pre.complete) { apply(); }
            else { pre.onload = apply; pre.onerror = apply; setTimeout(apply, 150); }
        }
        var sub = card.querySelector(".skins-card-sub");
        if (sub) sub.textContent = newSub || "";

        var tags = card.querySelector(".skins-card-tags");
        if (tags) {
            var cls = team === 2 ? "skins-badge-t" : (team === 3 ? "skins-badge-ct" : "skins-badge-both");
            var label = team === 2 ? "T" : (team === 3 ? "CT" : "ALL");
            var badge = '<span class="skins-eq-badge ' + cls + '">' + label + "</span>";
            var muted = tags.querySelector(".skins-tag-muted");
            if (muted) muted.remove();
            if (team === 1) {
                tags.querySelectorAll(".skins-eq-badge").forEach(function (b) { b.remove(); });
                tags.insertAdjacentHTML("afterbegin", badge);
            } else {
                var both = tags.querySelector(".skins-badge-both");
                if (both) both.remove();
                var same = tags.querySelector(team === 2 ? ".skins-badge-t" : ".skins-badge-ct");
                if (!same) tags.insertAdjacentHTML("afterbegin", badge);
            }
        }
        card.classList.add("is-equipped");

        // Refresh the sticker/keychain overlay on the card from the current
        // modal state, so applied stickers show instantly (no hard refresh).
        var well2 = card.querySelector(".skins-card-img");
        if (well2) {
            var old = well2.querySelector(".skins-card-stickers");
            if (old) old.remove();
            var ov = cardStickerOverlay(slotState, kcState);
            if (ov) well2.insertAdjacentHTML("beforeend", ov);
        }
    }

    // After equipping a weapon/knife/glove skin while its full grid is open,
    // reflect it in the grid without a server round-trip: badge the chosen paint
    // for the team, strip that team's badge off the others, and refresh the
    // editable fields (wear/StatTrak/name tag/stickers). sectionId is the grid's
    // data-defindex ("26" | "knife-5" | "glove-4").
    function patchOpenWeaponGrid(sectionId, paintId, team) {
        try {
            if (paintId === undefined) return;
            // Lazy weapon grids use [data-lazy-grid]; knife/glove grids are the
            // server-rendered section. Look in both.
            var grid = skinsContainer.querySelector('[data-lazy-grid][data-defindex="' + CSS.escape(String(sectionId)) + '"]')
                || skinsContainer.querySelector('[data-weapon-section][data-defindex="' + CSS.escape(String(sectionId)) + '"]');
            if (!grid) return;
            var sameCls = team === 2 ? "skins-badge-t" : (team === 3 ? "skins-badge-ct" : "skins-badge-both");
            var cards = grid.querySelectorAll('[data-skin-card], [data-knife-card], [data-glove-card]');
            cards.forEach(function (c) {
                var badges = c.querySelector(".skins-card-badges");
                var isTarget = c.dataset.paintid === String(paintId);
                if (team === 1) {
                    // Both supersedes everything on every other card.
                    if (!isTarget && badges) { badges.remove(); c.classList.remove("is-equipped"); }
                } else {
                                                                                 
                    if (!isTarget && badges) {
                        var b1 = badges.querySelector("." + sameCls);
                        if (b1) b1.remove();
                        var bb = badges.querySelector(".skins-badge-both");
                        if (bb) bb.remove();
                        if (!badges.querySelector(".skins-eq-badge")) { badges.remove(); c.classList.remove("is-equipped"); }
                    }
                }
            });
            // Badge the target card (weapon/knife/glove).
            var target = grid.querySelector('[data-skin-card][data-paintid="' + CSS.escape(String(paintId)) + '"], [data-knife-card][data-paintid="' + CSS.escape(String(paintId)) + '"], [data-glove-card][data-paintid="' + CSS.escape(String(paintId)) + '"]');
            if (target) {
                target.classList.add("is-equipped");
                var tb = target.querySelector(".skins-card-badges");
                if (!tb) {
                    tb = document.createElement("div");
                    tb.className = "skins-card-badges";
                    target.insertBefore(tb, target.firstChild);
                }
                if (team === 1) {
                    tb.innerHTML = teamBadge(1);
                } else {
                    var bothB = tb.querySelector(".skins-badge-both");
                    if (bothB) bothB.remove();
                    if (!tb.querySelector("." + sameCls)) tb.insertAdjacentHTML("beforeend", teamBadge(team));
                }
                // Refresh the sticker overlay on the opened grid's target card
                // AND keep its data-* in sync so re-opening shows the new state.
                var well = target.querySelector(".skins-card-img");
                if (well) {
                    var old = well.querySelector(".skins-card-stickers");
                    if (old) old.remove();
                    var ov = cardStickerOverlay(slotState, kcState);
                    if (ov) well.insertAdjacentHTML("beforeend", ov);

                    // Wear + StatTrak badge (lives inside .skins-card-img).
                    var wSel = well.querySelector(".skins-wear");
                    var wearRaw = wearInput ? parseFloat(wearInput.value) : NaN;
                    var stOn = !!(stattrakToggle && stattrakToggle.checked);
                    var wearLabel = isNaN(wearRaw) ? "" : wearShortLabel(wearRaw);
                    if (wearLabel) {
                        var wHtml = (stOn ? "ST\u2122 " : "") + escapeHtml(wearLabel);
                        if (wSel) wSel.innerHTML = wHtml;
                        else well.insertAdjacentHTML("beforeend", '<span class="skins-wear">' + wHtml + "</span>");
                    } else if (wSel) { wSel.remove(); }
                }

                // Name tag (sibling after .skins-card-sub, inside the card).
                var tagVal = nametagInput ? (nametagInput.value || "").trim() : "";
                var tagEl = target.querySelector(".skins-nametag");
                if (tagVal) {
                    if (tagEl) tagEl.textContent = '"' + tagVal + '"';
                    else {
                        var subEl = target.querySelector(".skins-card-sub");
                        var html = '<span class="skins-nametag">"' + escapeHtml(tagVal) + '"</span>';
                        if (subEl) subEl.insertAdjacentHTML("afterend", html);
                        else target.insertAdjacentHTML("beforeend", html);
                    }
                } else if (tagEl) { tagEl.remove(); }

                try {
                    target.dataset.stickers = JSON.stringify(slotState.map(function (id, i) {
                        return id > 0 ? { id: id, x: posState[i].x, y: posState[i].y, r: posState[i].r } : { id: 0, x: 0, y: 0, r: 0 };
                    }));
                    target.dataset.keychain = JSON.stringify({ id: kcState, x: 0, y: 0 });
                    if (wearInput) target.dataset.wear = wearInput.value;
                    if (seedInput) target.dataset.seed = seedInput.value;
                    target.dataset.stattrak = (stattrakToggle && stattrakToggle.checked) ? "1" : "0";
                    if (stattrakCountInput) target.dataset.stattrakCount = stattrakCountInput.value;
                    target.dataset.nametag = tagVal;
                } catch (e) {}
            }
        } catch (err) { if (window.console) console.error(err); }
    }

    var isSaving = false;
    if (configForm) {
        configForm.addEventListener("submit", function (e) {
            e.preventDefault();
            if (isSaving) return;
            isSaving = true;
            setSubmitLoading(true);

            var formData = new FormData(configForm);
            formData.set("ajax", "1");

            fetch(BASE + "/skins/save", {
                method: "POST", credentials: "same-origin",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: formData
            }).then(function (res) {
                if (!res.ok) throw new Error("Save request failed");
                return res.json();
            }).then(function (data) {
                if (!data || data.status !== "success") throw new Error("Save failed");
                // Decide whether a full re-render is actually needed BEFORE the
                // modal state is cleared. The local patch already reflects the
                // common case fully; only team-specific equips (T/CT) can split
                // an existing "Both" row in a way the patch can't show, and
                // music/pins need their own list re-render.
                var formaVal = formaInput ? formaInput.value : "";
                var teamVal = parseInt(teamInput ? teamInput.value : "1", 10) || 1;
                var parts = formaVal.split("-");
                var kind = parts[0];
                // Plain weapon equips (defindex is numeric, e.g. "26") are
                // reflected fully by the local patch above, so they skip the
                // heavy whole-page re-fetch — that was the slow part on T/CT
                // equips. Knives and gloves are different: the overview shows a
                // SINGLE summary card for "which knife/glove type is equipped",
                // and equipping a DIFFERENT type changes that card's identity
                // (image, name, data-defindex) in a way the in-place patch can't
                // represent — the patch targets the new type's selector, which
                // the stale card doesn't match, so the overview keeps the old
                // knife until a hard refresh. Refresh for those. Music/pins/
                // agents also need a refresh to update their own highlight.
                var needsFullRefresh = kind === "music" || kind === "pin" || kind === "agent"
                    || kind === "knife" || kind === "glove";
                // If the just-edited item's full grid is open, patch the equipped
                // highlight + editable fields there too (no server round-trip).
                // Works for weapons (defindex = "26"), knives ("knife-5") and
                // gloves ("glove-4") — resolve the section id + the paint id.
                if (!needsFullRefresh) {
                    var sectionId, paintId;
                    if (/^\d+$/.test(kind)) { sectionId = kind; paintId = parts[1]; }
                    else { sectionId = kind + "-" + parts[1]; paintId = parts[2]; } // knife/glove
                    if (activeDefindex === sectionId && paintId !== undefined) {
                        patchOpenWeaponGrid(sectionId, paintId, teamVal);
                    }
                }

                // Patch the overview card in place FIRST (while the modal still
                // holds the equipped values), so the change is visible instantly.
                patchOverviewCard();
                closeModal();
                showToast(t("toast_skin_equipped"));
                isSaving = false;
                setSubmitLoading(false);

                // Only pay for the heavy re-render when the local patch can't
                // fully represent the result; otherwise the page is already
                // correct and we skip the 1-2s refresh entirely.
                if (needsFullRefresh) {
                    refreshLoadoutState().catch(function (err) {
                        if (window.console) console.error(err);
                    });
                }
            }).catch(function (err) {
                if (window.console) console.error(err);
                showToast(t("toast_save_failed"), "error");
                isSaving = false;
                setSubmitLoading(false);
            });
        });
    }

    // Some WeaponPaints collectibles/pins/skins have no artwork in the upstream
    // image repo, so the CDN returns 404. Instead of an empty box, mark the card
    // image as "missing" (CSS then shows a neutral placeholder glyph). Captured
    // on the container so it also covers client-rendered Music/Pins cards.
    (function brokenImageFallback() {
        var root = document.querySelector(".skins-app");
        if (!root) return;
        root.addEventListener("error", function (e) {
            var img = e.target;
            if (!img || img.tagName !== "IMG" || !img.closest(".skins-card-img")) return;
            if (img.dataset.fallbackApplied) return;
            img.dataset.fallbackApplied = "1";
            img.style.display = "none";
            var well = img.closest(".skins-card-img");
            if (well) well.classList.add("skins-img-missing");
        }, true); // capture: image error events don't bubble
    })();
})();

/**
 * 3D preview for the Skins editor (loaded on demand by skins.js when enabled
 * in Settings). Renders the weapon model with the selected paint texture and
 * draws placed stickers through the model's second UV set, where the
 * WeaponPaints free offsets are relative to the weapon's first sticker slot.
 * A saved charm hangs from its game position (or the weapon's default spot).
 * Missing models or WebGL2 keep the 2D image; missing paint textures leave
 * the base 3D model available for sticker placement.
 */
import * as THREE from '../vendor/three/three.module.min.js';
import { GLTFLoader } from '../vendor/three/addons/GLTFLoader.js';
import { OrbitControls } from '../vendor/three/addons/OrbitControls.js';
import { RoomEnvironment } from '../vendor/three/addons/RoomEnvironment.js';
import { MeshoptDecoder } from '../vendor/three/addons/meshopt_decoder.module.js';

const SLOTS = 5;
const SKIP_MATERIALS = /bare_arm|scope|glass|lens/i;

let renderer = null, scene = null, camera = null, controls = null, envTex = null;
let host = null, resizeObs = null, pivot = null, meshes = [], token = 0;
let home = null, placing = false, activeSlot = -1, placeCb = null;
let anchors = [], modelRoot = null, kc = null, kcLimits = null, kcSources = [], kcSeq = 0;
const jsonCache = new Map();
const charmCache = new Map();
const kcTexCache = new Map();
const kcRay = new THREE.Raycaster();
const stickers = Array.from({ length: SLOTS }, () => ({ src: '', x: 0, y: 0, r: 0 }));
const blank = new THREE.DataTexture(new Uint8Array([0, 0, 0, 0]), 1, 1);
blank.needsUpdate = true;
const U = { stXf: { value: Array.from({ length: SLOTS }, () => new THREE.Vector4(0, 0, 0, 0)) } };
for (let i = 0; i < SLOTS; i++) U['stTex' + i] = { value: blank };
const texCache = new Map();
const gltfLoader = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder);
const texLoader = new THREE.TextureLoader().setCrossOrigin('anonymous');

export function supported() {
    try { return !!document.createElement('canvas').getContext('webgl2'); } catch (e) { return false; }
}

function ensureRenderer() {
    if (renderer) return;
    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;
    scene = new THREE.Scene();
    const pmrem = new THREE.PMREMGenerator(renderer);
    envTex = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    pmrem.dispose();
    scene.environment = envTex;
    const key = new THREE.DirectionalLight(0xffffff, 1.4);
    key.position.set(3, 5, 4);
    scene.add(key, new THREE.AmbientLight(0xffffff, 0.35));
    camera = new THREE.PerspectiveCamera(32, 1, 0.05, 2000);
    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.enablePan = false;
    controls.rotateSpeed = 0.8;
    bindPointer(renderer.domElement);
}

function resize() {
    if (!host || !renderer) return;
    const w = Math.max(1, host.clientWidth), h = Math.max(1, host.clientHeight);
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
}

function tick() {
    controls.update();
    stepKeychain();
    renderer.render(scene, camera);
}

function disposeModel() {
    removeKeychain();
    modelRoot = null;
    if (!pivot) return;
    scene.remove(pivot);
    pivot.traverse((o) => {
        if (!o.isMesh) return;
        o.geometry.dispose();
        (Array.isArray(o.material) ? o.material : [o.material]).forEach((m) => {
            ['map', 'metalnessMap', 'roughnessMap', 'normalMap', 'aoMap'].forEach((k) => { if (m[k]) m[k].dispose(); });
            m.dispose();
        });
    });
    pivot = null;
    meshes = [];
}

async function firstOk(urls, load, label) {
    let error = null;
    for (const url of urls) {
        try { return await load(url); } catch (e) { error = e; }
    }
    if (urls.length) console.warn('[Skins3D] ' + label + ' unavailable.', error);
    return null;
}

function loadJson(name) {
    if (!jsonCache.has(name)) {
        const url = new URL('./' + name, import.meta.url);
        url.search = new URL(import.meta.url).search;
        jsonCache.set(name, fetch(url).then((response) => {
            if (!response.ok) throw new Error(name + ' request failed: ' + response.status);
            return response.json();
        }).catch((error) => {
            jsonCache.delete(name);
            console.warn('[Skins3D] ' + name + ' unavailable.', error);
            return null;
        }));
    }
    return jsonCache.get(name);
}

/** Game keychain limits [xmin, xmax, ymin, ymax, zmin, zmax] for the weapon, or null. */
async function loadKeychainLimits(defindex, legacy) {
    const data = defindex ? await loadJson('skins3d-keychains.json') : null;
    const l = data && data.limits && data.limits[String(defindex)];
    const v = l && ((legacy && l.legacy) || l.hd);
    return Array.isArray(v) && v.length === 6 && v.every(Number.isFinite) ? v : null;
}

async function loadStickerLayout(defindex, legacy) {
    if (!defindex) return [];
    const data = await loadJson('skins3d-sticker-layouts.json');
    if (!data) return [];
    const all = data[String(defindex)] || [];
    if (!Array.isArray(all)) {
        console.warn('[Skins3D] Invalid sticker layout for weapon ' + defindex);
        return [];
    }
    let selected = all.filter((a) => a && !!a.legacy === !!legacy);
    if (!selected.length) selected = all.filter((a) => a && !a.legacy);
    const slots = [];
    for (const a of selected) {
        if (!Number.isInteger(a.slot) || a.slot < 0
            || !Array.isArray(a.offsets) || !Number.isFinite(a.offsets[0]) || !Number.isFinite(a.offsets[1])
            || !Number.isFinite(a.scale) || a.scale <= 0) {
            console.warn('[Skins3D] Invalid sticker slot for weapon ' + defindex, a);
            return [];
        }
        if (a.slot >= SLOTS) continue;
        slots[a.slot] = { x: a.offsets[0], y: a.offsets[1], scale: a.scale };
    }
    return slots;
}

function loadTexture(url, srgb) {
    return texLoader.loadAsync(url).then((t) => {
        t.flipY = false;
        t.wrapS = t.wrapT = THREE.RepeatWrapping;
        t.colorSpace = srgb ? THREE.SRGBColorSpace : THREE.NoColorSpace;
        t.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
        t.userData.url = url;
        return t;
    });
}

async function loadPaint(sources, folder, paint) {
    const dir = encodeURIComponent(folder) + '/' + encodeURIComponent(paint);
    const urls = [];
    sources.forEach((s) => { if (s.textures) ['webp', 'png'].forEach((ext) => urls.push(s.textures + dir + '.' + ext)); });
    const map = await firstOk(urls, (u) => loadTexture(u, true), 'paint ' + folder + '/' + paint);
    if (!map) return null;
    const metalUrl = map.userData.url.replace(/(\.\w+)$/, '_metal$1');
    const metal = await loadTexture(metalUrl, false).catch(() => null);
    return { map, metal };
}

function injectStickers(material) {
    material.onBeforeCompile = (shader) => {
        Object.assign(shader.uniforms, U);
        shader.vertexShader = 'attribute vec2 stuv;\nvarying vec2 vStUv;\n' + shader.vertexShader
            .replace('#include <uv_vertex>', '#include <uv_vertex>\n\tvStUv = stuv;');
        let samplers = '';
        let blend = '';
        for (let i = 0; i < SLOTS; i++) {
            samplers += 'uniform sampler2D stTex' + i + ';\n';
            blend += '\t{ vec4 s = stSample(stTex' + i + ', stXf[' + i + '], vStUv); diffuseColor.rgb = mix(diffuseColor.rgb, s.rgb, s.a); }\n';
        }
        shader.fragmentShader = 'varying vec2 vStUv;\nuniform vec4 stXf[' + SLOTS + '];\n' + samplers
            + 'vec4 stSample(sampler2D t, vec4 xf, vec2 uv) {\n'
            + '\tif (xf.w <= 0.0) return vec4(0.0);\n'
            + '\tvec2 d = uv - xf.xy; float c = cos(xf.z), sn = sin(xf.z);\n'
            + '\tvec2 q = vec2(c * d.x - sn * d.y, sn * d.x + c * d.y) / xf.w + 0.5;\n'
            + '\tif (q.x < 0.0 || q.y < 0.0 || q.x > 1.0 || q.y > 1.0) return vec4(0.0);\n'
            + '\treturn texture2D(t, vec2(q.x, 1.0 - q.y));\n}\n'
            + shader.fragmentShader.replace('#include <map_fragment>', '#include <map_fragment>\n' + blend);
    };
    material.customProgramCacheKey = () => 'cs2-stickers';
    material.needsUpdate = true;
}

function prepareModel(gltf, legacy) {
    const root = gltf.scene;
    const named = (suffix) => {
        let hit = null;
        root.traverse((o) => { if (!hit && o.name && o.name.endsWith(suffix)) hit = o; });
        return hit;
    };
    const lg = named('body_legacy'), hd = named('body_hd');
    if (lg && hd) (legacy ? hd : lg).visible = false;

    const box = new THREE.Box3();
    root.traverse((o) => {
        if (!o.isMesh || !isShown(o)) return;
        o.material = Array.isArray(o.material) ? o.material.map((m) => m.clone()) : o.material.clone();
        meshes.push(o);
        if (o.geometry.getAttribute('uv1')) {
            o.geometry.setAttribute('stuv', o.geometry.getAttribute('uv1'));
            (Array.isArray(o.material) ? o.material : [o.material]).forEach((m) => { if (!SKIP_MATERIALS.test(m.name || '')) injectStickers(m); });
        }
    });
    root.updateMatrixWorld(true);
    meshes.forEach((m) => box.expandByObject(m));
    const center = box.getCenter(new THREE.Vector3());
    root.position.sub(center);
    modelRoot = root;
    pivot = new THREE.Group();
    pivot.add(root);
    pivot.rotation.y = Math.PI;
    scene.add(pivot);
    return box.getSize(new THREE.Vector3());
}

function isShown(o) {
    for (let p = o; p; p = p.parent) if (!p.visible) return false;
    return true;
}

function frame(size) {
    // Fit the side view to the stage: the weapon's length to the width, its height to the height
    const tanV = Math.tan(THREE.MathUtils.degToRad(camera.fov / 2));
    const fit = Math.max((size.y / 2) / tanV, (size.x / 2) / (tanV * camera.aspect));
    const dist = fit * 1.12 + size.z / 2;
    home = new THREE.Vector3(0, size.y * 0.12, dist);
    camera.position.copy(home);
    camera.near = dist / 100;
    camera.far = dist * 20;
    camera.updateProjectionMatrix();
    controls.target.set(0, 0, 0);
    controls.minDistance = dist * 0.35;
    controls.maxDistance = dist * 2.8;
    controls.update();
}

function applyPaint(tex) {
    meshes.forEach((o) => {
        (Array.isArray(o.material) ? o.material : [o.material]).forEach((m) => {
            if (SKIP_MATERIALS.test(m.name || '')) return;
            m.map = tex.map;
            if (tex.metal) m.metalnessMap = tex.metal;
            m.color && m.color.set(0xffffff);
            m.needsUpdate = true;
        });
    });
}

/**
 * Show spec {sources, model, folder, paint, legacy, defindex} inside el.
 * Resolves true with the paint, 'untextured' with only the base model,
 * or false when 3D is unavailable.
 */
export async function show(el, spec) {
    const my = ++token;
    if (!supported() || !spec || !spec.model) return false;
    ensureRenderer();
    if (host !== el) {
        host = el;
        el.appendChild(renderer.domElement);
        if (resizeObs) resizeObs.disconnect();
        resizeObs = new ResizeObserver(resize);
        resizeObs.observe(el);
    }
    resize();
    disposeModel();
    kcSeq++;
    renderer.setAnimationLoop(null);
    renderer.domElement.style.visibility = 'hidden';

    const sources = spec.sources || [];
    const modelUrls = sources.map((s) => s.models + encodeURIComponent(spec.model) + '.glb');
    const paint = String(spec.paint || '0');
    const [gltf, tex, layout, limits] = await Promise.all([
        firstOk(modelUrls, (u) => gltfLoader.loadAsync(u), 'model ' + spec.model),
        paint !== '0' ? loadPaint(sources, spec.folder || spec.model, paint) : Promise.resolve(null),
        loadStickerLayout(spec.defindex, !!spec.legacy),
        loadKeychainLimits(spec.defindex, !!spec.legacy),
    ]);
    if (my !== token) return false;
    if (!gltf) return false;

    anchors = layout;
    kcLimits = limits;
    kcSources = Array.isArray(spec.keychains) ? spec.keychains : [];
    const size = prepareModel(gltf, !!spec.legacy);
    if (tex) applyPaint(tex);
    frame(size);
    syncUniforms();
    renderer.domElement.style.visibility = '';
    renderer.setAnimationLoop(tick);
    return paint === '0' || tex ? true : 'untextured';
}

export function hide() {
    token++;
    placing = false;
    activeSlot = -1;
    placeCb = null;
    anchors = [];
    kcSeq++;
    kcLimits = null;
    setStickers([]);
    if (controls) controls.enabled = true;
    if (renderer) renderer.setAnimationLoop(null);
    disposeModel();
}

export function resetView() {
    if (!home) return;
    camera.position.copy(home);
    controls.target.set(0, 0, 0);
    controls.update();
}

function stickerTexture(src) {
    if (!src) return Promise.resolve(blank);
    if (!texCache.has(src)) {
        texCache.set(src, texLoader.loadAsync(src).then((t) => {
            t.colorSpace = THREE.SRGBColorSpace;
            t.flipY = false;
            t.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
            return t;
        }).catch((error) => {
            texCache.delete(src);
            console.warn('[Skins3D] Sticker texture unavailable: ' + src, error);
            return blank;
        }));
    }
    return texCache.get(src);
}

function stickerPlacement(s, i) {
    const free = s.x !== 0 || s.y !== 0;
    const a = anchors[free ? 0 : i] || anchors[0];
    if (!a) return null;
    return { x: a.x + (free ? s.x : 0), y: a.y + (free ? s.y : 0), size: 1 / a.scale };
}

function syncUniforms() {
    stickers.forEach((s, i) => {
        const p = stickerPlacement(s, i);
        const src = s.src && p ? s.src : '';
        U.stXf.value[i].set(p ? p.x + 0.5 : 0, p ? p.y + 0.5 : 0, THREE.MathUtils.degToRad(s.r), src ? p.size : 0);
        U['stTex' + i].value = blank;
        const my = token;
        stickerTexture(src).then((t) => {
            if (my === token && stickers[i].src === src) U['stTex' + i].value = t;
        });
    });
}

/** list: [{src, x, y, r}]; zero offsets draw at the slot's game-default spot. */
export function setStickers(list) {
    for (let i = 0; i < SLOTS; i++) {
        const s = (list && list[i]) || {};
        const x = +s.x || 0, y = +s.y || 0;
        Object.assign(stickers[i], { src: s.src || '', x, y, r: +s.r || 0 });
    }
    syncUniforms();
}

/*
 * Charms. Game keychain coordinates (inches; X forward, Y left, Z up) are the
 * weapon model's own space, held by the glTF scene as (X, Z, -Y). The charm's
 * own model (meters) comes from the keychain sources; without one its picture
 * hangs on a ring.
 */
function keychainLocal(p) {
    return new THREE.Vector3(p.x, p.z, -p.y);
}

function clampKeychain(p) {
    const l = kcLimits;
    if (!l) return p;
    p.x = Math.max(l[0], Math.min(l[1], p.x));
    p.y = Math.max(l[2], Math.min(l[3], p.y));
    p.z = Math.max(l[4], Math.min(l[5], p.z));
    return p;
}

// A spot on the sticker side of the weapon inside the game limits
function defaultKeychain() {
    const l = kcLimits;
    if (!modelRoot || !l) return null;
    scene.updateMatrixWorld(true);
    for (const fz of [0.7, 0.55, 0.85, 0.4]) {
        for (const fx of [0.5, 0.42, 0.58, 0.34, 0.66, 0.26, 0.74]) {
            const x = l[0] + fx * (l[1] - l[0]);
            const z = l[4] + fz * (l[5] - l[4]);
            const from = modelRoot.localToWorld(new THREE.Vector3(x, z, -100));
            const to = modelRoot.localToWorld(new THREE.Vector3(x, z, 0));
            kcRay.set(from, to.sub(from).normalize());
            const hit = kcRay.intersectObjects(meshes, false)[0];
            if (!hit) continue;
            const y = -modelRoot.worldToLocal(hit.point.clone()).z;
            if (y < l[2] - 0.5 || y > l[3] + 0.5) continue;
            return clampKeychain({ x, y, z });
        }
    }
    return clampKeychain({ x: (l[0] + l[1]) / 2, y: l[3], z: (l[4] + l[5]) / 2 });
}

function keychainFrame() {
    const group = new THREE.Group();
    const swing = new THREE.Group();
    group.add(swing);
    const metal = new THREE.MeshStandardMaterial({ color: 0xc9ccd2, metalness: 1, roughness: 0.28 });
    return { group, swing, metal, vz: 0, vx: 0, vt: 0, t: 0, rest: 0 };
}

// Reach of the hanging part: how far down and sideways, and its back face per height band
function measureKeychain(k) {
    k.swing.updateMatrixWorld(true);
    const b = new THREE.Box3().setFromObject(k.swing);
    const down = Math.max(0.5, -b.min.y);
    const BANDS = 6;
    const back = new Array(BANDS).fill(-Infinity);
    const v = new THREE.Vector3();
    k.swing.traverse((child) => {
        if (!child.isMesh || !child.geometry || !child.geometry.attributes.position) return;
        const pos = child.geometry.attributes.position;
        const step = Math.max(1, Math.floor(pos.count / 4000));
        for (let i = 0; i < pos.count; i += step) {
            v.fromBufferAttribute(pos, i).applyMatrix4(child.matrixWorld);
            const f = -v.y / down;
            if (f < 0.3 || f > 1.001) continue;
            const band = Math.min(BANDS - 1, Math.floor(f * BANDS));
            back[band] = Math.max(back[band], v.z);
        }
    });
    k.hang = {
        down,
        half: Math.max(0.2, Math.abs(b.min.x), Math.abs(b.max.x)),
        bands: back.map((z, i) => (Number.isFinite(z) ? { L: ((i + 0.5) / BANDS) * down, z } : null)).filter(Boolean),
    };
    return k;
}

// Outermost weapon surface on the sticker side (-Z) at a height, across a width
function surfaceAt(x, y, half) {
    let outer = Infinity;
    for (const fx of [-1, -0.5, 0, 0.5, 1]) {
        const from = modelRoot.localToWorld(new THREE.Vector3(x + fx * half, y, -100));
        const to = modelRoot.localToWorld(new THREE.Vector3(x + fx * half, y, 100));
        kcRay.set(from, to.sub(from).normalize());
        const hit = kcRay.intersectObjects(meshes, false)[0];
        if (hit) outer = Math.min(outer, modelRoot.worldToLocal(hit.point.clone()).z);
    }
    return outer;
}

// The ring sits on the weapon surface; the charm leans out just enough not to sink into the model
function fitKeychain(k) {
    if (!modelRoot || !meshes.length || !k.hang) return;
    const p = k.group.position;
    scene.updateMatrixWorld(true);
    const top = surfaceAt(p.x, p.y, 0.15);
    const off = Number.isFinite(top) ? Math.min(0, top - p.z) : 0;
    k.swing.position.z = off;
    const ringZ = p.z + off;
    const surf = k.hang.bands.map((band) => ({ ...band, outer: surfaceAt(p.x, p.y - band.L, k.hang.half) }));
    let lean = 0;
    for (let a = 0; a <= 0.9; a += 0.015) {
        lean = a;
        if (surf.every((s) => !Number.isFinite(s.outer) || ringZ + s.z * Math.cos(a) - s.L * Math.sin(a) <= s.outer - 0.02)) break;
    }
    k.rest = lean;
    if (k.swing.rotation.x < k.rest) k.swing.rotation.x = k.rest;
}

function buildKeychainImage(texture) {
    const k = keychainFrame();
    const { swing, metal } = k;
    swing.add(new THREE.Mesh(new THREE.TorusGeometry(0.16, 0.035, 8, 24), metal));
    for (let i = 0; i < 3; i++) {
        const link = new THREE.Mesh(new THREE.TorusGeometry(0.085, 0.022, 6, 16), metal);
        link.position.y = -0.22 - i * 0.14;
        if (i % 2) link.rotation.y = Math.PI / 2;
        swing.add(link);
    }
    const h = 1.3;
    const w = h * ((texture.image && texture.image.width || 1) / (texture.image && texture.image.height || 1));
    const plane = new THREE.Mesh(
        new THREE.PlaneGeometry(w, h),
        new THREE.MeshStandardMaterial({ map: texture, transparent: true, alphaTest: 0.05, side: THREE.DoubleSide, roughness: 0.5, metalness: 0.1 })
    );
    plane.rotation.y = Math.PI;
    plane.position.y = -0.6 - h / 2 + 0.08;
    swing.add(plane);
    return measureKeychain(Object.assign(k, { body: plane, bodyTurn: Math.PI }));
}

function buildKeychainModel(model) {
    const k = keychainFrame();
    model.scale.setScalar(39.37);
    model.rotation.y = Math.PI / 2;
    k.swing.add(model);
    model.traverse((child) => {
        if (child.isMesh) child.material = Array.isArray(child.material) ? child.material.map((m) => m.clone()) : child.material.clone();
    });
    return measureKeychain(Object.assign(k, { body: model, bodyTurn: Math.PI / 2 }));
}

function charmModel(file) {
    if (!charmCache.has(file)) {
        const urls = kcSources.map((base) => base + encodeURIComponent(file) + '.glb');
        charmCache.set(file, firstOk(urls, (u) => gltfLoader.loadAsync(u), 'charm ' + file).then((g) => {
            if (!g) charmCache.delete(file);
            return g ? g.scene : null;
        }));
    }
    return charmCache.get(file).then((model) => (model ? model.clone(true) : null));
}

function charmTexture(src) {
    if (!kcTexCache.has(src)) {
        kcTexCache.set(src, texLoader.loadAsync(src).then((t) => {
            t.colorSpace = THREE.SRGBColorSpace;
            return t;
        }).catch((error) => {
            kcTexCache.delete(src);
            console.warn('[Skins3D] Charm image unavailable: ' + src, error);
            return null;
        }));
    }
    return kcTexCache.get(src);
}

// Textures stay cached for the next use; only geometry and materials are freed
function disposeKeychain(k) {
    k.group.traverse((child) => {
        if (child.geometry) child.geometry.dispose();
        if (child.material) (Array.isArray(child.material) ? child.material : [child.material]).forEach((m) => m.dispose());
    });
}

function removeKeychain() {
    if (!kc) return;
    if (kc.group.parent) kc.group.parent.remove(kc.group);
    disposeKeychain(kc);
    kc = null;
}

// Damped pendulum: swings sideways and outwards (never into the weapon) and reacts to turning the view
function stepKeychain() {
    const k = kc;
    if (!k) return;
    const now = performance.now();
    const dt = Math.min(0.05, (now - (k.last || now)) / 1000);
    k.last = now;
    const az = controls.getAzimuthalAngle();
    const pol = controls.getPolarAngle();
    if (k.az !== undefined) {
        let d = az - k.az;
        if (d > Math.PI) d -= Math.PI * 2;
        if (d < -Math.PI) d += Math.PI * 2;
        k.vz += d * 7;
        k.vx += Math.abs(d) * 2.5 + (pol - k.pol) * 5;
        k.vt += d * 9;
    }
    k.az = az;
    k.pol = pol;
    if (!dt) return;
    const r = k.swing.rotation;
    for (let i = 0; i < 2; i++) {
        const h = dt / 2;
        k.vz += (-150 * r.z - 3.2 * k.vz) * h;
        k.vx += (-150 * r.x - 3.2 * k.vx) * h;
        k.vt += (-60 * k.t - 2.6 * k.vt) * h;
        r.z = Math.max(-1, Math.min(1, r.z + k.vz * h));
        r.x += k.vx * h;
        k.t = Math.max(-0.8, Math.min(0.8, k.t + k.vt * h));
        if (r.x < k.rest) { r.x = k.rest; k.vx = Math.abs(k.vx) * 0.35; }
        if (r.x > k.rest + 0.9) { r.x = k.rest + 0.9; k.vx = -Math.abs(k.vx) * 0.3; }
    }
    if (k.body) k.body.rotation.y = k.bodyTurn + k.t;
}

/**
 * item: {id, image, x, y, z} in game keychain coordinates (all zero = the
 * weapon's default spot), or null to remove the charm.
 */
export async function setKeychain(item) {
    const my = ++kcSeq;
    const id = item ? parseInt(item.id, 10) || 0 : 0;
    if (!modelRoot || !id) { removeKeychain(); return; }
    let pos = { x: +item.x || 0, y: +item.y || 0, z: +item.z || 0 };
    pos = pos.x || pos.y || pos.z ? clampKeychain(pos) : defaultKeychain();
    if (!pos) { removeKeychain(); return; }

    const source = id + '|' + (item.image || '');
    if (!kc || kc.source !== source) {
        const data = await loadJson('skins3d-keychains.json');
        const file = data && data.models && data.models[String(id)];
        let built = null;
        if (file && kcSources.length) {
            const model = await charmModel(file);
            if (model) built = buildKeychainModel(model);
        }
        if (!built && item.image) {
            const tex = await charmTexture(item.image);
            if (tex) built = buildKeychainImage(tex);
        }
        if (my !== kcSeq || !modelRoot) { if (built) disposeKeychain(built); return; }
        removeKeychain();
        if (!built) return;
        kc = built;
        kc.source = source;
        modelRoot.add(kc.group);
    }
    kc.group.position.copy(keychainLocal(pos));
    fitKeychain(kc);
    kc.vz += 6.6;
    kc.vx += 2.75;
    kc.vt += 4.4;
}

export function hasStickerLayout() {
    return !!anchors[0] && meshes.some((m) => !!m.geometry.getAttribute('uv1'));
}

/** Enable click/drag placement for one slot; cb(slot, x, y) receives game coordinates. */
export function setPlacement(slot, cb) {
    activeSlot = slot;
    placing = slot >= 0 && slot < SLOTS && hasStickerLayout();
    placeCb = cb || null;
    if (renderer) renderer.domElement.style.cursor = placing ? 'crosshair' : '';
}

const ray = new THREE.Raycaster();
const ndc = new THREE.Vector2();
function hitUv(ev) {
    const r = renderer.domElement.getBoundingClientRect();
    ndc.set(((ev.clientX - r.left) / r.width) * 2 - 1, -((ev.clientY - r.top) / r.height) * 2 + 1);
    ray.setFromCamera(ndc, camera);
    const hits = ray.intersectObjects(meshes.filter(isShown), false);
    for (const h of hits) {
        if (h.uv1 && h.uv1.x >= 0 && h.uv1.x <= 1 && h.uv1.y >= 0 && h.uv1.y <= 1) return h.uv1;
    }
    return null;
}

function emit(uv) {
    const base = anchors[0];
    if (!base) return;
    const x = Math.round(Math.max(-1, Math.min(1, uv.x - 0.5 - base.x)) * 10000) / 10000;
    const y = Math.round(Math.max(-1, Math.min(1, uv.y - 0.5 - base.y)) * 10000) / 10000;
    if (placeCb) placeCb(activeSlot, x === 0 && y === 0 ? 0.0001 : x, y);
}

function bindPointer(canvas) {
    let down = null, dragging = false;
    canvas.addEventListener('pointerdown', (ev) => {
        if (!placing || !pivot) return;
        down = { x: ev.clientX, y: ev.clientY };
        const s = stickers[activeSlot];
        const p = s && stickerPlacement(s, activeSlot);
        const uv = hitUv(ev);
        if (s && s.src && p && uv && Math.hypot(uv.x - (p.x + 0.5), uv.y - (p.y + 0.5)) < p.size * 0.6) {
            dragging = true;
            controls.enabled = false;
            canvas.setPointerCapture(ev.pointerId);
        }
    });
    canvas.addEventListener('pointermove', (ev) => {
        if (!dragging) return;
        const uv = hitUv(ev);
        if (uv) emit(uv);
    });
    const end = (ev) => {
        if (dragging) {
            dragging = false;
            controls.enabled = true;
        } else if (down && placing && Math.hypot(ev.clientX - down.x, ev.clientY - down.y) < 5) {
            const uv = hitUv(ev);
            if (uv) emit(uv);
        }
        down = null;
    };
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', () => { dragging = false; controls.enabled = true; down = null; });
}

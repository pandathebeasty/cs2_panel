/**
 * 3D preview for the Skins editor (loaded on demand by skins.js when enabled
 * in Settings). Renders the weapon model with the selected paint texture and
 * draws placed stickers through the model's second UV set, where the
 * WeaponPaints free offsets are relative to the weapon's first sticker slot.
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
let anchors = [], layoutPromise = null;
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
    renderer.render(scene, camera);
}

function disposeModel() {
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

async function loadStickerLayout(defindex, legacy) {
    if (!defindex) return [];
    if (!layoutPromise) {
        const url = new URL('./skins3d-sticker-layouts.json', import.meta.url);
        url.search = new URL(import.meta.url).search;
        layoutPromise = fetch(url).then((response) => {
            if (!response.ok) throw new Error('Sticker layout request failed: ' + response.status);
            return response.json();
        }).catch((error) => {
            layoutPromise = null;
            console.warn('[Skins3D] Sticker layouts unavailable.', error);
            return null;
        });
    }
    const data = await layoutPromise;
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
    const radius = Math.max(size.x, size.y, size.z) / 2;
    const fit = radius / Math.tan(THREE.MathUtils.degToRad(camera.fov / 2));
    const dist = fit * (camera.aspect < 1.4 ? 1.35 / Math.max(0.6, camera.aspect / 1.4) : 1.15);
    home = new THREE.Vector3(0, radius * 0.18, dist);
    camera.position.copy(home);
    camera.near = dist / 100;
    camera.far = dist * 20;
    camera.updateProjectionMatrix();
    controls.target.set(0, 0, 0);
    controls.minDistance = dist * 0.35;
    controls.maxDistance = dist * 2.2;
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
    renderer.setAnimationLoop(null);
    renderer.domElement.style.visibility = 'hidden';

    const sources = spec.sources || [];
    const modelUrls = sources.map((s) => s.models + encodeURIComponent(spec.model) + '.glb');
    const paint = String(spec.paint || '0');
    const [gltf, tex, layout] = await Promise.all([
        firstOk(modelUrls, (u) => gltfLoader.loadAsync(u), 'model ' + spec.model),
        paint !== '0' ? loadPaint(sources, spec.folder || spec.model, paint) : Promise.resolve(null),
        loadStickerLayout(spec.defindex, !!spec.legacy),
    ]);
    if (my !== token) return false;
    if (!gltf) return false;

    anchors = layout;
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

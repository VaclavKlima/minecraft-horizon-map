<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Map Interface</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100">
        <div class="grid min-h-screen grid-rows-[auto_1fr]">
            <header class="border-b border-slate-800 bg-slate-900/90 px-4 py-3">
                <div class="mx-auto flex max-w-7xl items-center justify-between gap-3">
                    <div>
                        <h1 class="text-base font-semibold tracking-wide">Minecraft Horizon Map</h1>
                        <p class="text-xs text-slate-400">Drag to pan, mouse wheel to zoom</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <select
                            id="projection-select"
                            class="rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none"
                        >
                            <option value="birds-eye">Birds-eye</option>
                            <option value="isometric">Isometric</option>
                        </select>

                        <button
                            id="render-map"
                            type="button"
                            class="rounded-md bg-emerald-500 px-3 py-2 text-sm font-semibold text-slate-900 transition hover:bg-emerald-400"
                        >
                            Queue Map Jobs
                        </button>
                    </div>
                </div>
            </header>

            <main class="relative overflow-hidden">
                <div id="map-viewport" class="absolute inset-0 cursor-grab overflow-hidden bg-slate-950 active:cursor-grabbing">
                    <canvas id="birds-eye-canvas" class="absolute inset-0"></canvas>
                    <canvas id="isometric-canvas" class="absolute inset-0 hidden"></canvas>
                    <div id="tile-layer" class="absolute inset-0 hidden"></div>
                </div>

                <aside class="pointer-events-none absolute left-4 top-4 z-20 w-72 rounded-lg border border-slate-700/80 bg-slate-900/85 p-3 text-xs">
                    <p id="map-status" class="text-slate-300">Loading map manifest...</p>
                    <div class="mt-2 grid grid-cols-2 gap-1 text-slate-200">
                        <span>Zoom:</span><span id="map-zoom">-</span>
                        <span>Cursor X:</span><span id="map-cursor-x">-</span>
                        <span>Cursor Z:</span><span id="map-cursor-z">-</span>
                        <span>Tiles:</span><span id="map-tiles">-</span>
                        <span>Visible:</span><span id="map-visible-tiles">-</span>
                        <span>Manifest ms:</span><span id="map-manifest-ms">-</span>
                        <span>Render ms:</span><span id="map-render-ms">-</span>
                        <span>Polls:</span><span id="map-poll-count">0</span>
                    </div>
                </aside>

                <aside class="absolute right-4 top-4 z-20 w-56 rounded-md border border-slate-700/80 bg-slate-900/90 p-2 text-[11px]">
                    <div class="mb-1 flex items-center justify-between text-slate-300">
                        <span>Overview</span>
                        <span id="overview-status" class="text-slate-400">Click/drag</span>
                    </div>
                    <canvas id="overview-map" class="block w-full rounded-sm border border-slate-700/80 bg-slate-950"></canvas>
                </aside>
            </main>
        </div>

        <script>
            const viewport = document.getElementById('map-viewport');
            const tileLayer = document.getElementById('tile-layer');
            const birdsEyeCanvas = document.getElementById('birds-eye-canvas');
            const birdsEyeContext = birdsEyeCanvas.getContext('2d', { alpha: true, desynchronized: true });
            const isometricCanvas = document.getElementById('isometric-canvas');
            const webglContext = isometricCanvas.getContext('webgl2', { alpha: true, antialias: false, premultipliedAlpha: true });
            const isometricContext = webglContext === null
                ? isometricCanvas.getContext('2d', { alpha: true, desynchronized: true })
                : null;
            const statusEl = document.getElementById('map-status');
            const zoomEl = document.getElementById('map-zoom');
            const cursorXEl = document.getElementById('map-cursor-x');
            const cursorZEl = document.getElementById('map-cursor-z');
            const tilesEl = document.getElementById('map-tiles');
            const visibleTilesEl = document.getElementById('map-visible-tiles');
            const manifestMsEl = document.getElementById('map-manifest-ms');
            const renderMsEl = document.getElementById('map-render-ms');
            const pollCountEl = document.getElementById('map-poll-count');
            const renderButton = document.getElementById('render-map');
            const projectionSelect = document.getElementById('projection-select');
            const overviewCanvas = document.getElementById('overview-map');
            const overviewStatusEl = document.getElementById('overview-status');
            const overviewContext = overviewCanvas.getContext('2d');
            const overviewBaseCanvas = document.createElement('canvas');
            const overviewBaseContext = overviewBaseCanvas.getContext('2d');

            let manifest = null;
            let zoom = 0;
            let offsetX = 0;
            let offsetY = 0;
            let isDragging = false;
            let dragStartX = 0;
            let dragStartY = 0;
            let dragOriginX = 0;
            let dragOriginY = 0;
            let birdsEyeLayerCache = new Map();
            let selectedProjection = 'birds-eye';
            let requestedViewState = null;
            let urlSyncTimeout = null;
            let activeBatchId = null;
            let batchPollTimer = null;
            let renderFrameRequested = false;
            let cursorFrameRequested = false;
            let lastPointerClientX = 0;
            let lastPointerClientY = 0;
            let pollCount = 0;
            let birdsEyeCanvasWidth = 0;
            let birdsEyeCanvasHeight = 0;
            let isometricCanvasWidth = 0;
            let isometricCanvasHeight = 0;
            let isometricCacheKey = '';
            let webglProgram = null;
            let webglBuffer = null;
            let webglPositionAttribute = -1;
            let webglTexCoordAttribute = -1;
            let webglResolutionUniform = null;
            let webglTextureUniform = null;
            let overviewMapState = null;
            let overviewDragging = false;
            let overviewBaseCacheKey = '';
            let overviewBaseReady = false;
            let isometricOverviewLayerVersion = 0;
            let isometricOverviewPreloadTimer = null;
            let overviewRefreshPending = false;
            let overviewImage = null;
            let overviewImageUrl = '';
            let overviewImagePendingUrl = '';
            let isometricLayerLoaderWorker = null;
            let isometricLayerLoaderWorkerUrl = null;
            let isometricWorkerLoadFailures = 0;
            let isometricWorkerDisabled = true;

            const isometricLayerCache = new Map();

            const maxIsometricOverzoomLevels = 2;
            const maxWebglTextureUploadsPerFrame = 2;
            const maxVisibleLayerRequestsPerFrame = 2;
            const maxVisibleLayerRequestsWhileDragging = 1;
            const maxTextureUploadsWhileDragging = 1;
            const workerFallbackDelayMs = 2200;
            const maxWorkerFailuresBeforeDisable = 3;
            const usingWebglIsometric = webglContext !== null;
            const urlParams = new URLSearchParams(window.location.search);

            function markIsometricLayerLoaded(cacheEntry) {
                cacheEntry.loaded = true;
                isometricOverviewLayerVersion++;
                if (!isDragging) {
                    scheduleIsometricOverviewPreload();
                }
                requestRenderTiles();
            }

            function markIsometricLayerFailed(cacheEntry) {
                cacheEntry.failed = true;
                isometricOverviewLayerVersion++;
                if (!isDragging) {
                    scheduleIsometricOverviewPreload();
                }
            }

            function loadIsometricLayerWithImage(cacheEntry, highPriority) {
                if (cacheEntry.image !== null) {
                    return;
                }

                const image = new Image();
                image.decoding = 'async';
                image.loading = highPriority ? 'eager' : 'lazy';
                image.fetchPriority = highPriority ? 'high' : 'low';
                cacheEntry.image = image;
                image.onload = () => {
                    markIsometricLayerLoaded(cacheEntry);
                };
                image.onerror = () => {
                    markIsometricLayerFailed(cacheEntry);
                };
                image.src = cacheEntry.url;
            }

            function disableIsometricWorkerLoader(reason = 'worker disabled') {
                if (isometricWorkerDisabled) {
                    return;
                }

                isometricWorkerDisabled = true;

                if (isometricLayerLoaderWorker !== null) {
                    isometricLayerLoaderWorker.terminate();
                    isometricLayerLoaderWorker = null;
                }

                if (isometricLayerLoaderWorkerUrl !== null) {
                    URL.revokeObjectURL(isometricLayerLoaderWorkerUrl);
                    isometricLayerLoaderWorkerUrl = null;
                }

                console.warn('isometric-worker disabled, using Image fallback only', {
                    reason,
                    failures: isometricWorkerLoadFailures,
                });

                for (const entry of isometricLayerCache.values()) {
                    if (!entry.loaded && !entry.failed && entry.image === null) {
                        entry.requested = false;
                        loadIsometricLayerWithImage(entry, false);
                    }
                }
            }

            function ensureIsometricLayerLoaderWorker() {
                if (
                    isometricWorkerDisabled
                    || !window.isSecureContext
                    ||
                    isometricLayerLoaderWorker !== null
                    || typeof Worker === 'undefined'
                    || typeof createImageBitmap === 'undefined'
                ) {
                    return isometricLayerLoaderWorker !== null;
                }

                const workerSource = `
                    self.onmessage = async (event) => {
                        const payload = event.data || {};
                        if (payload.type !== 'load' || typeof payload.key !== 'string' || typeof payload.url !== 'string') {
                            return;
                        }

                        try {
                            const response = await fetch(payload.url, { cache: 'force-cache', credentials: 'same-origin' });
                            if (!response.ok) {
                                throw new Error('HTTP ' + response.status);
                            }

                            const blob = await response.blob();
                            const bitmap = await createImageBitmap(blob);
                            self.postMessage({ type: 'loaded', key: payload.key, bitmap }, [bitmap]);
                        } catch (error) {
                            self.postMessage({
                                type: 'failed',
                                key: payload.key,
                                message: error instanceof Error ? error.message : 'unknown',
                            });
                        }
                    };
                `;

                try {
                    const blob = new Blob([workerSource], { type: 'text/javascript' });
                    isometricLayerLoaderWorkerUrl = URL.createObjectURL(blob);
                    isometricLayerLoaderWorker = new Worker(isometricLayerLoaderWorkerUrl);
                } catch (error) {
                    if (isometricLayerLoaderWorkerUrl !== null) {
                        URL.revokeObjectURL(isometricLayerLoaderWorkerUrl);
                        isometricLayerLoaderWorkerUrl = null;
                    }
                    isometricLayerLoaderWorker = null;
                    return false;
                }
                isometricLayerLoaderWorker.onmessage = event => {
                    const payload = event.data || {};
                    const key = typeof payload.key === 'string' ? payload.key : '';

                    if (key === '') {
                        return;
                    }

                    const cacheEntry = isometricLayerCache.get(key);
                    if (!cacheEntry) {
                        if (payload.type === 'loaded' && payload.bitmap && typeof payload.bitmap.close === 'function') {
                            payload.bitmap.close();
                        }
                        return;
                    }

                    if (payload.type === 'loaded' && payload.bitmap) {
                        if (cacheEntry.loaded && cacheEntry.image !== null) {
                            if (typeof payload.bitmap.close === 'function') {
                                payload.bitmap.close();
                            }
                            return;
                        }

                        cacheEntry.bitmap = payload.bitmap;
                        markIsometricLayerLoaded(cacheEntry);
                        return;
                    }

                    cacheEntry.requested = false;
                    cacheEntry.bitmap = null;
                    isometricWorkerLoadFailures++;

                    if (isometricWorkerLoadFailures >= maxWorkerFailuresBeforeDisable) {
                        disableIsometricWorkerLoader(payload.message ?? 'repeated worker load failures');
                    } else {
                        console.warn('isometric-worker load failed; falling back to Image loader', {
                            key,
                            failures: isometricWorkerLoadFailures,
                            message: payload.message ?? 'unknown',
                        });
                    }

                    if (!cacheEntry.loaded && !cacheEntry.failed) {
                        loadIsometricLayerWithImage(cacheEntry, false);
                        cacheEntry.requested = true;
                        return;
                    }

                    markIsometricLayerFailed(cacheEntry);
                };
                isometricLayerLoaderWorker.onerror = event => {
                    isometricWorkerLoadFailures++;
                    disableIsometricWorkerLoader(event.message || 'worker runtime error');
                };

                return true;
            }

            if (urlParams.get('projection') === 'isometric') {
                selectedProjection = 'isometric';
            }

            const requestedZoom = Number.parseInt(urlParams.get('zoom') ?? '', 10);
            const requestedOffsetX = Number.parseInt(urlParams.get('x') ?? '', 10);
            const requestedOffsetY = Number.parseInt(urlParams.get('y') ?? '', 10);
            if (
                Number.isInteger(requestedZoom)
                && Number.isInteger(requestedOffsetX)
                && Number.isInteger(requestedOffsetY)
            ) {
                requestedViewState = {
                    zoom: requestedZoom,
                    x: requestedOffsetX,
                    y: requestedOffsetY,
                };
            }

            projectionSelect.value = selectedProjection;

            function levelInfo(level) {
                return manifest.levels[String(level)];
            }

            function manifestZoomLevels() {
                if (!manifest?.levels) {
                    return [];
                }

                return Object.keys(manifest.levels)
                    .map(value => Number.parseInt(value, 10))
                    .filter(Number.isInteger)
                    .sort((left, right) => left - right);
            }

            function uiMaxZoom() {
                const levels = manifestZoomLevels();

                if (levels.length === 0) {
                    return 0;
                }

                if (selectedProjection !== 'isometric') {
                    return levels[levels.length - 1];
                }

                const nativeMaxZoom = Number.isInteger(manifest?.native_max_zoom)
                    ? manifest.native_max_zoom
                    : levels[levels.length - 1];
                const absoluteMax = levels[levels.length - 1];

                return Math.min(absoluteMax, nativeMaxZoom + maxIsometricOverzoomLevels);
            }

            function uiMinZoom() {
                const levels = manifestZoomLevels();

                if (levels.length === 0) {
                    return 0;
                }

                return levels[0];
            }

            function updateUrlState() {
                const params = new URLSearchParams();
                params.set('projection', selectedProjection);

                if (manifest) {
                    params.set('zoom', String(zoom));
                    params.set('x', String(Math.round(offsetX)));
                    params.set('y', String(Math.round(offsetY)));
                }

                const nextUrl = `${window.location.pathname}?${params.toString()}`;
                window.history.replaceState({}, '', nextUrl);
            }

            function scheduleUrlStateSync() {
                if (urlSyncTimeout !== null) {
                    window.clearTimeout(urlSyncTimeout);
                }

                urlSyncTimeout = window.setTimeout(updateUrlState, 80);
            }

            function mapDivisor(level) {
                const nativeMaxZoom = manifest.native_max_zoom ?? manifest.max_zoom;

                return 2 ** (nativeMaxZoom - level);
            }

            function clampOffsets() {
                const info = levelInfo(zoom);
                const maxOffsetX = Math.max(0, info.width - viewport.clientWidth);
                const maxOffsetY = Math.max(0, info.height - viewport.clientHeight);

                offsetX = Math.max(0, Math.min(maxOffsetX, offsetX));
                offsetY = Math.max(0, Math.min(maxOffsetY, offsetY));
            }

            function parseRegionCoordinates(regionFile) {
                const match = String(regionFile ?? '').match(/^r\.(-?\d+)\.(-?\d+)\.mca$/);

                if (!match) {
                    return null;
                }

                return {
                    regionX: Number.parseInt(match[1], 10),
                    regionZ: Number.parseInt(match[2], 10),
                };
            }

            function isometricPlacements() {
                if (!manifest) {
                    return [];
                }

                const placements = Array.isArray(manifest.placements) ? manifest.placements : [];
                if (placements.length > 0) {
                    return placements
                        .map(placement => ({
                            region_x: Number.parseInt(String(placement.region_x ?? ''), 10),
                            region_z: Number.parseInt(String(placement.region_z ?? ''), 10),
                            offset_x: Number(placement.offset_x ?? 0),
                            offset_y: Number(placement.offset_y ?? 0),
                            width: Number(placement.width ?? 0),
                            height: Number(placement.height ?? 0),
                        }))
                        .filter(placement => Number.isInteger(placement.region_x) && Number.isInteger(placement.region_z));
                }

                const layers = Array.isArray(manifest.image_layers) ? manifest.image_layers : [];

                return layers
                    .map(layer => {
                        const coords = parseRegionCoordinates(layer.file ?? '');

                        if (!coords) {
                            return null;
                        }

                        return {
                            region_x: coords.regionX,
                            region_z: coords.regionZ,
                            offset_x: Number(layer.offset_x ?? 0),
                            offset_y: Number(layer.offset_y ?? 0),
                            width: Number(layer.width ?? 0),
                            height: Number(layer.height ?? 0),
                        };
                    })
                    .filter(Boolean);
            }

            function isometricPixelScale() {
                const placements = isometricPlacements();
                const placementWithWidth = placements.find(placement => Number.isFinite(placement.width) && placement.width > 0);

                if (placementWithWidth) {
                    return Math.max(1, Math.round(placementWithWidth.width / 1026));
                }

                const sourceWidth = Number(manifest?.source_width ?? 0);
                if (sourceWidth > 0) {
                    return Math.max(1, Math.round(sourceWidth / 1026));
                }

                return 1;
            }

            function isometricRegionAnchorX() {
                return 512 * isometricPixelScale();
            }

            function findPlacementByWorld(worldX, worldZ) {
                const placements = isometricPlacements();
                if (placements.length === 0) {
                    return null;
                }

                const regionX = Math.floor(worldX / 512);
                const regionZ = Math.floor(worldZ / 512);
                const exactMatch = placements.find(
                    placement => placement.region_x === regionX && placement.region_z === regionZ
                );

                if (exactMatch) {
                    return exactMatch;
                }

                let nearestPlacement = null;
                let nearestDistance = Number.POSITIVE_INFINITY;

                for (const placement of placements) {
                    const dx = placement.region_x - regionX;
                    const dz = placement.region_z - regionZ;
                    const distance = (dx * dx) + (dz * dz);

                    if (distance < nearestDistance) {
                        nearestDistance = distance;
                        nearestPlacement = placement;
                    }
                }

                return nearestPlacement;
            }

            function worldCenterFromIsometricViewport() {
                if (!manifest) {
                    return null;
                }

                const placements = isometricPlacements();
                if (placements.length === 0) {
                    const divisor = mapDivisor(zoom);

                    return {
                        x: (manifest.world_min_x ?? 0) + ((offsetX + (viewport.clientWidth / 2)) * divisor),
                        z: (manifest.world_min_z ?? 0) + ((offsetY + (viewport.clientHeight / 2)) * divisor),
                    };
                }

                const divisor = mapDivisor(zoom);
                const pixelScale = isometricPixelScale();
                const anchorX = isometricRegionAnchorX();
                const sourceX = (offsetX + (viewport.clientWidth / 2)) * divisor;
                const sourceY = (offsetY + (viewport.clientHeight / 2)) * divisor;
                let best = null;
                let bestPenalty = Number.POSITIVE_INFINITY;

                for (const placement of placements) {
                    const relativeX = sourceX - placement.offset_x - anchorX;
                    const relativeY = sourceY - placement.offset_y;
                    const localX = (relativeX + (2 * relativeY)) / (2 * pixelScale);
                    const localZ = ((2 * relativeY) - relativeX) / (2 * pixelScale);
                    const overflowX = localX < 0 ? -localX : Math.max(0, localX - 512);
                    const overflowZ = localZ < 0 ? -localZ : Math.max(0, localZ - 512);
                    const penalty = overflowX + overflowZ;

                    if (penalty >= bestPenalty) {
                        continue;
                    }

                    bestPenalty = penalty;
                    best = {
                        placement,
                        localX,
                        localZ,
                    };
                }

                if (!best) {
                    return null;
                }

                const clampedLocalX = Math.max(0, Math.min(511.999, best.localX));
                const clampedLocalZ = Math.max(0, Math.min(511.999, best.localZ));

                return {
                    x: (best.placement.region_x * 512) + clampedLocalX,
                    z: (best.placement.region_z * 512) + clampedLocalZ,
                };
            }

            function applyWorldCenterToIsometricViewport(center) {
                if (!manifest || !center) {
                    return;
                }

                const placement = findPlacementByWorld(center.x, center.z);
                if (!placement) {
                    return;
                }

                const divisor = mapDivisor(zoom);
                const pixelScale = isometricPixelScale();
                const anchorX = isometricRegionAnchorX();
                const localX = center.x - (placement.region_x * 512);
                const localZ = center.z - (placement.region_z * 512);
                const sourceX = placement.offset_x + anchorX + ((localX - localZ) * pixelScale);
                const sourceY = placement.offset_y + (((localX + localZ) * pixelScale) / 2);
                offsetX = (sourceX / divisor) - (viewport.clientWidth / 2);
                offsetY = (sourceY / divisor) - (viewport.clientHeight / 2);
            }

            function currentViewportWorldCenter() {
                if (!manifest) {
                    return null;
                }

                if (selectedProjection === 'isometric') {
                    return worldCenterFromIsometricViewport();
                }

                const divisor = mapDivisor(zoom);
                const worldMinX = manifest.world_min_x ?? 0;
                const worldMinZ = manifest.world_min_z ?? 0;

                return {
                    x: worldMinX + ((offsetX + (viewport.clientWidth / 2)) * divisor),
                    z: worldMinZ + ((offsetY + (viewport.clientHeight / 2)) * divisor),
                };
            }

            function applyViewportWorldCenter(center) {
                if (!manifest || !center) {
                    return;
                }

                if (selectedProjection === 'isometric') {
                    applyWorldCenterToIsometricViewport(center);
                    return;
                }

                const divisor = mapDivisor(zoom);
                const worldMinX = manifest.world_min_x ?? 0;
                const worldMinZ = manifest.world_min_z ?? 0;
                offsetX = ((center.x - worldMinX) / divisor) - (viewport.clientWidth / 2);
                offsetY = ((center.z - worldMinZ) / divisor) - (viewport.clientHeight / 2);
            }

            function setStatus(message) {
                statusEl.textContent = message;
            }

            function buildQueryString() {
                const params = new URLSearchParams();
                params.set('projection', selectedProjection);

                return params.toString();
            }

            function resetActiveTiles() {
                birdsEyeLayerCache.forEach(layer => {
                    if (layer?.bitmap && typeof layer.bitmap.close === 'function') {
                        layer.bitmap.close();
                    }
                });
                birdsEyeLayerCache.clear();
                clearBirdsEyeCanvas();
                clearIsometricCanvas();
            }

            function syncProjectionLayers() {
                const isometricMode = selectedProjection === 'isometric';
                birdsEyeCanvas.classList.toggle('hidden', isometricMode);
                isometricCanvas.classList.toggle('hidden', !isometricMode);
                tileLayer.classList.add('hidden');
            }

            function initializeWebglRenderer() {
                if (!usingWebglIsometric || webglContext === null || webglProgram !== null) {
                    return;
                }

                const vertexSource = `
                    attribute vec2 a_position;
                    attribute vec2 a_texCoord;
                    uniform vec2 u_resolution;
                    varying vec2 v_texCoord;
                    void main() {
                        vec2 zeroToOne = a_position / u_resolution;
                        vec2 zeroToTwo = zeroToOne * 2.0;
                        vec2 clipSpace = zeroToTwo - 1.0;
                        gl_Position = vec4(clipSpace * vec2(1.0, -1.0), 0.0, 1.0);
                        v_texCoord = a_texCoord;
                    }
                `;
                const fragmentSource = `
                    precision mediump float;
                    varying vec2 v_texCoord;
                    uniform sampler2D u_texture;
                    void main() {
                        gl_FragColor = texture2D(u_texture, v_texCoord);
                    }
                `;

                function compileShader(type, source) {
                    const shader = webglContext.createShader(type);

                    if (shader === null) {
                        return null;
                    }

                    webglContext.shaderSource(shader, source);
                    webglContext.compileShader(shader);

                    if (!webglContext.getShaderParameter(shader, webglContext.COMPILE_STATUS)) {
                        webglContext.deleteShader(shader);
                        return null;
                    }

                    return shader;
                }

                const vertexShader = compileShader(webglContext.VERTEX_SHADER, vertexSource);
                const fragmentShader = compileShader(webglContext.FRAGMENT_SHADER, fragmentSource);

                if (vertexShader === null || fragmentShader === null) {
                    return;
                }

                const program = webglContext.createProgram();

                if (program === null) {
                    return;
                }

                webglContext.attachShader(program, vertexShader);
                webglContext.attachShader(program, fragmentShader);
                webglContext.linkProgram(program);
                webglContext.deleteShader(vertexShader);
                webglContext.deleteShader(fragmentShader);

                if (!webglContext.getProgramParameter(program, webglContext.LINK_STATUS)) {
                    webglContext.deleteProgram(program);
                    return;
                }

                const buffer = webglContext.createBuffer();

                if (buffer === null) {
                    webglContext.deleteProgram(program);
                    return;
                }

                webglProgram = program;
                webglBuffer = buffer;
                webglPositionAttribute = webglContext.getAttribLocation(program, 'a_position');
                webglTexCoordAttribute = webglContext.getAttribLocation(program, 'a_texCoord');
                webglResolutionUniform = webglContext.getUniformLocation(program, 'u_resolution');
                webglTextureUniform = webglContext.getUniformLocation(program, 'u_texture');

                webglContext.enable(webglContext.BLEND);
                webglContext.blendFunc(webglContext.SRC_ALPHA, webglContext.ONE_MINUS_SRC_ALPHA);
            }

            function clearIsometricCanvas() {
                if (usingWebglIsometric && webglContext !== null) {
                    webglContext.viewport(0, 0, isometricCanvas.width, isometricCanvas.height);
                    webglContext.clearColor(0, 0, 0, 0);
                    webglContext.clear(webglContext.COLOR_BUFFER_BIT);
                    return;
                }

                if (isometricContext === null) {
                    return;
                }

                if (isometricCanvasWidth <= 0 || isometricCanvasHeight <= 0) {
                    return;
                }

                isometricContext.clearRect(0, 0, isometricCanvasWidth, isometricCanvasHeight);
            }

            function ensureBirdsEyeCanvasSize() {
                if (birdsEyeContext === null) {
                    return;
                }

                const viewportWidth = Math.max(1, viewport.clientWidth);
                const viewportHeight = Math.max(1, viewport.clientHeight);
                const ratio = Math.max(1, window.devicePixelRatio || 1);
                const nextWidth = Math.max(1, Math.floor(viewportWidth * ratio));
                const nextHeight = Math.max(1, Math.floor(viewportHeight * ratio));

                if (birdsEyeCanvas.width !== nextWidth || birdsEyeCanvas.height !== nextHeight) {
                    birdsEyeCanvas.width = nextWidth;
                    birdsEyeCanvas.height = nextHeight;
                }

                birdsEyeCanvas.style.width = `${viewportWidth}px`;
                birdsEyeCanvas.style.height = `${viewportHeight}px`;
                birdsEyeCanvasWidth = viewportWidth;
                birdsEyeCanvasHeight = viewportHeight;
                birdsEyeContext.setTransform(ratio, 0, 0, ratio, 0, 0);
            }

            function clearBirdsEyeCanvas() {
                if (birdsEyeContext === null) {
                    return;
                }

                if (birdsEyeCanvasWidth <= 0 || birdsEyeCanvasHeight <= 0) {
                    return;
                }

                birdsEyeContext.clearRect(0, 0, birdsEyeCanvasWidth, birdsEyeCanvasHeight);
            }

            function ensureIsometricCanvasSize() {
                if (!usingWebglIsometric && isometricContext === null) {
                    return;
                }

                const viewportWidth = Math.max(1, viewport.clientWidth);
                const viewportHeight = Math.max(1, viewport.clientHeight);
                const ratio = usingWebglIsometric ? 1 : Math.max(1, window.devicePixelRatio || 1);
                const nextWidth = Math.max(1, Math.floor(viewportWidth * ratio));
                const nextHeight = Math.max(1, Math.floor(viewportHeight * ratio));

                if (isometricCanvas.width !== nextWidth || isometricCanvas.height !== nextHeight) {
                    isometricCanvas.width = nextWidth;
                    isometricCanvas.height = nextHeight;
                }

                isometricCanvas.style.width = `${viewportWidth}px`;
                isometricCanvas.style.height = `${viewportHeight}px`;
                isometricCanvasWidth = viewportWidth;
                isometricCanvasHeight = viewportHeight;

                if (!usingWebglIsometric && isometricContext !== null) {
                    isometricContext.setTransform(ratio, 0, 0, ratio, 0, 0);
                }
            }

            function createWebglTextureFromSource(source) {
                if (!usingWebglIsometric || webglContext === null) {
                    return null;
                }

                const texture = webglContext.createTexture();

                if (texture === null) {
                    return null;
                }

                webglContext.bindTexture(webglContext.TEXTURE_2D, texture);
                webglContext.pixelStorei(webglContext.UNPACK_PREMULTIPLY_ALPHA_WEBGL, true);
                webglContext.texParameteri(webglContext.TEXTURE_2D, webglContext.TEXTURE_WRAP_S, webglContext.CLAMP_TO_EDGE);
                webglContext.texParameteri(webglContext.TEXTURE_2D, webglContext.TEXTURE_WRAP_T, webglContext.CLAMP_TO_EDGE);
                webglContext.texParameteri(webglContext.TEXTURE_2D, webglContext.TEXTURE_MIN_FILTER, webglContext.NEAREST);
                webglContext.texParameteri(webglContext.TEXTURE_2D, webglContext.TEXTURE_MAG_FILTER, webglContext.NEAREST);
                webglContext.texImage2D(
                    webglContext.TEXTURE_2D,
                    0,
                    webglContext.RGBA,
                    webglContext.RGBA,
                    webglContext.UNSIGNED_BYTE,
                    source
                );

                const glError = webglContext.getError();
                if (glError !== webglContext.NO_ERROR) {
                    webglContext.deleteTexture(texture);
                    return null;
                }

                return texture;
            }

            function syncBirdsEyeLayerCache() {
                const layers = Array.isArray(manifest?.image_layers) ? manifest.image_layers : [];
                const nextKeys = new Set();

                for (const layer of layers) {
                    const key = `${layer.file}:${layer.url}`;
                    nextKeys.add(key);

                    if (birdsEyeLayerCache.has(key)) {
                        continue;
                    }

                    birdsEyeLayerCache.set(key, {
                        key,
                        image: null,
                        bitmap: null,
                        loaded: false,
                        failed: false,
                        requested: false,
                        url: layer.url,
                    });
                }

                for (const existingKey of birdsEyeLayerCache.keys()) {
                    if (!nextKeys.has(existingKey)) {
                        const staleEntry = birdsEyeLayerCache.get(existingKey);
                        if (staleEntry?.bitmap && typeof staleEntry.bitmap.close === 'function') {
                            staleEntry.bitmap.close();
                        }
                        birdsEyeLayerCache.delete(existingKey);
                    }
                }
            }

            function requestBirdsEyeLayerImage(cacheEntry, highPriority) {
                if (!cacheEntry || cacheEntry.requested || cacheEntry.loaded || cacheEntry.failed) {
                    return;
                }

                const image = new Image();
                image.draggable = false;
                image.decoding = 'async';
                image.loading = highPriority ? 'eager' : 'lazy';
                image.fetchPriority = highPriority ? 'high' : 'low';
                cacheEntry.image = image;
                cacheEntry.requested = true;

                image.onload = async () => {
                    cacheEntry.failed = false;
                    cacheEntry.loaded = true;
                    if (typeof createImageBitmap === 'function') {
                        try {
                            cacheEntry.bitmap = await createImageBitmap(image);
                        } catch {
                            cacheEntry.bitmap = null;
                        }
                    }
                    requestRenderTiles();
                };
                image.onerror = () => {
                    cacheEntry.loaded = false;
                    cacheEntry.failed = true;
                    requestRenderTiles();
                };
                image.src = cacheEntry.url;
            }

            function syncIsometricLayerCache() {
                const layers = Array.isArray(manifest?.image_layers) ? manifest.image_layers : [];
                const nextKeys = new Set();

                for (const layer of layers) {
                    const key = `${layer.file}:${layer.url}`;
                    nextKeys.add(key);

                    if (isometricLayerCache.has(key)) {
                        continue;
                    }

                    const entry = {
                        key,
                        image: null,
                        bitmap: null,
                        loaded: false,
                        failed: false,
                        texture: null,
                        requested: false,
                        url: layer.url,
                        workerRequestedAt: 0,
                    };
                    isometricLayerCache.set(key, entry);
                }

                for (const existingKey of isometricLayerCache.keys()) {
                    if (!nextKeys.has(existingKey)) {
                        const staleEntry = isometricLayerCache.get(existingKey);
                        if (usingWebglIsometric && webglContext !== null && staleEntry?.texture) {
                            webglContext.deleteTexture(staleEntry.texture);
                        }
                        if (staleEntry?.bitmap && typeof staleEntry.bitmap.close === 'function') {
                            staleEntry.bitmap.close();
                        }
                        isometricLayerCache.delete(existingKey);
                    }
                }
            }

            function requestIsometricLayerImage(cacheEntry, highPriority) {
                if (!cacheEntry || cacheEntry.requested || cacheEntry.loaded || cacheEntry.failed) {
                    return;
                }

                cacheEntry.requested = true;
                if (ensureIsometricLayerLoaderWorker()) {
                    cacheEntry.workerRequestedAt = performance.now();
                    isometricLayerLoaderWorker.postMessage({
                        type: 'load',
                        key: cacheEntry.key,
                        url: cacheEntry.url,
                        priority: highPriority ? 'high' : 'low',
                    });

                    window.setTimeout(() => {
                        if (cacheEntry.loaded || cacheEntry.failed || cacheEntry.image !== null) {
                            return;
                        }

                        if (cacheEntry.workerRequestedAt <= 0) {
                            return;
                        }

                        loadIsometricLayerWithImage(cacheEntry, highPriority);
                    }, workerFallbackDelayMs);
                    return;
                }

                loadIsometricLayerWithImage(cacheEntry, highPriority);
            }

            function stopIsometricOverviewPreload() {
                if (isometricOverviewPreloadTimer !== null) {
                    window.clearTimeout(isometricOverviewPreloadTimer);
                    isometricOverviewPreloadTimer = null;
                }
            }

            function requestNextIsometricOverviewLayer() {
                if (!manifest || selectedProjection !== 'isometric') {
                    return false;
                }

                const layers = Array.isArray(manifest?.image_layers) ? manifest.image_layers : [];

                for (const layer of layers) {
                    const cacheKey = `${layer.file}:${layer.url}`;
                    const cacheEntry = isometricLayerCache.get(cacheKey);

                    if (!cacheEntry || cacheEntry.failed || cacheEntry.loaded) {
                        continue;
                    }

                    if (!cacheEntry.requested) {
                        requestIsometricLayerImage(cacheEntry, false);
                        return true;
                    }
                }

                return false;
            }

            function scheduleIsometricOverviewPreload() {
                stopIsometricOverviewPreload();

                if (!manifest || selectedProjection !== 'isometric' || isDragging) {
                    return;
                }

                isometricOverviewPreloadTimer = window.setTimeout(() => {
                    isometricOverviewPreloadTimer = null;

                    if (requestNextIsometricOverviewLayer()) {
                        scheduleIsometricOverviewPreload();
                    }
                }, 180);
            }

            function visibleIsometricRegionFiles() {
                if (!manifest || selectedProjection !== 'isometric') {
                    return [];
                }

                const layers = Array.isArray(manifest?.image_layers) ? manifest.image_layers : [];
                const divisor = mapDivisor(zoom);
                const visible = [];

                for (const layer of layers) {
                    const layerX = Math.floor((layer.offset_x ?? 0) / divisor);
                    const layerY = Math.floor((layer.offset_y ?? 0) / divisor);
                    const layerWidth = Math.max(1, Math.ceil((layer.width ?? 0) / divisor));
                    const layerHeight = Math.max(1, Math.ceil((layer.height ?? 0) / divisor));
                    const drawX = layerX - offsetX;
                    const drawY = layerY - offsetY;
                    const intersectsViewport = (drawX + layerWidth) >= 0
                        && (drawY + layerHeight) >= 0
                        && drawX <= viewport.clientWidth
                        && drawY <= viewport.clientHeight;

                    if (intersectsViewport && typeof layer.file === 'string' && layer.file !== '') {
                        visible.push(layer.file);
                    }
                }

                return Array.from(new Set(visible));
            }

            function requestRenderTiles() {
                if (renderFrameRequested) {
                    return;
                }

                renderFrameRequested = true;
                window.requestAnimationFrame(() => {
                    renderFrameRequested = false;
                    renderTiles();
                });
            }

            function scheduleCursorReadout(clientX, clientY) {
                lastPointerClientX = clientX;
                lastPointerClientY = clientY;

                if (cursorFrameRequested) {
                    return;
                }

                cursorFrameRequested = true;
                window.requestAnimationFrame(() => {
                    cursorFrameRequested = false;

                    if (!manifest) {
                        return;
                    }

                    const rect = viewport.getBoundingClientRect();
                    const pointerX = lastPointerClientX - rect.left;
                    const pointerY = lastPointerClientY - rect.top;
                    const divisor = mapDivisor(zoom);
                    const worldX = Math.floor((manifest.world_min_x ?? 0) + ((offsetX + pointerX) * divisor));
                    const worldZ = Math.floor((manifest.world_min_z ?? 0) + ((offsetY + pointerY) * divisor));
                    cursorXEl.textContent = String(worldX);
                    cursorZEl.textContent = String(worldZ);
                });
            }

            function syncOverviewImage() {
                if (!manifest) {
                    return;
                }

                const minimap = manifest.minimap ?? null;
                const nextUrl = typeof minimap?.url === 'string' ? minimap.url : '';

                if (nextUrl === '') {
                    overviewImage = null;
                    overviewImageUrl = '';
                    overviewImagePendingUrl = '';
                    return;
                }

                if (
                    nextUrl === overviewImageUrl
                    || nextUrl === overviewImagePendingUrl
                ) {
                    return;
                }

                overviewImage = null;
                overviewImageUrl = '';
                overviewImagePendingUrl = nextUrl;
                const image = new Image();
                image.decoding = 'async';
                image.loading = 'eager';
                image.fetchPriority = 'high';
                image.onload = () => {
                    if (overviewImagePendingUrl !== nextUrl) {
                        return;
                    }

                    overviewImage = image;
                    overviewImageUrl = nextUrl;
                    overviewImagePendingUrl = '';
                    requestRenderTiles();
                };
                image.onerror = () => {
                    if (overviewImagePendingUrl === nextUrl) {
                        overviewImagePendingUrl = '';
                    }
                };
                image.src = nextUrl;
            }

            function stopBatchPolling() {
                activeBatchId = null;
                pollCount = 0;
                pollCountEl.textContent = '0';

                if (batchPollTimer !== null) {
                    window.clearTimeout(batchPollTimer);
                    batchPollTimer = null;
                }
            }

            function viewportWorldBounds() {
                if (!manifest) {
                    return null;
                }

                const divisor = mapDivisor(zoom);
                const worldMinX = manifest.world_min_x ?? 0;
                const worldMinZ = manifest.world_min_z ?? 0;
                const minX = Math.floor(worldMinX + (offsetX * divisor));
                const minZ = Math.floor(worldMinZ + (offsetY * divisor));
                const maxX = Math.floor(worldMinX + ((offsetX + viewport.clientWidth) * divisor));
                const maxZ = Math.floor(worldMinZ + ((offsetY + viewport.clientHeight) * divisor));
                const focusX = Math.floor((minX + maxX) / 2);
                const focusZ = Math.floor((minZ + maxZ) / 2);

                return {
                    focus_world_x: focusX,
                    focus_world_z: focusZ,
                    viewport_min_world_x: minX,
                    viewport_min_world_z: minZ,
                    viewport_max_world_x: maxX,
                    viewport_max_world_z: maxZ,
                };
            }

            function renderOverviewMap() {
                if (!manifest || overviewContext === null || overviewBaseContext === null) {
                    return;
                }

                const info = levelInfo(zoom);
                if (!info) {
                    return;
                }

                const cssWidth = overviewCanvas.clientWidth;

                if (cssWidth <= 0) {
                    return;
                }

                const divisor = mapDivisor(zoom);
                const sourceMapWidth = Math.max(1, Number.parseInt(String(manifest.source_width ?? Math.round(info.width * divisor)), 10));
                const sourceMapHeight = Math.max(1, Number.parseInt(String(manifest.source_height ?? Math.round(info.height * divisor)), 10));
                const desiredHeight = Math.max(96, Math.min(220, Math.round(cssWidth * (sourceMapHeight / sourceMapWidth))));

                if (overviewCanvas.style.height !== `${desiredHeight}px`) {
                    overviewCanvas.style.height = `${desiredHeight}px`;
                }

                if (overviewCanvas.width !== cssWidth || overviewCanvas.height !== desiredHeight) {
                    overviewCanvas.width = cssWidth;
                    overviewCanvas.height = desiredHeight;
                }

                const scale = Math.min(cssWidth / sourceMapWidth, desiredHeight / sourceMapHeight);
                const drawnWidth = sourceMapWidth * scale;
                const drawnHeight = sourceMapHeight * scale;
                const originX = Math.floor((cssWidth - drawnWidth) / 2);
                const originY = Math.floor((desiredHeight - drawnHeight) / 2);
                const overviewKey = `${selectedProjection}:${manifest.generated_at ?? ''}:${cssWidth}:${desiredHeight}:${sourceMapWidth}:${sourceMapHeight}`;

                syncOverviewImage();
                const minimap = manifest.minimap ?? null;
                const hasMinimap = typeof minimap?.url === 'string' && minimap.url !== '';

                if (hasMinimap) {
                    const minimapSourceWidth = Math.max(
                        1,
                        Number.parseInt(String(minimap?.source_width ?? sourceMapWidth), 10)
                    );
                    const minimapSourceHeight = Math.max(
                        1,
                        Number.parseInt(String(minimap?.source_height ?? sourceMapHeight), 10)
                    );
                    const minimapScale = Math.min(cssWidth / minimapSourceWidth, desiredHeight / minimapSourceHeight);
                    const minimapDrawnWidth = minimapSourceWidth * minimapScale;
                    const minimapDrawnHeight = minimapSourceHeight * minimapScale;
                    const minimapOriginX = Math.floor((cssWidth - minimapDrawnWidth) / 2);
                    const minimapOriginY = Math.floor((desiredHeight - minimapDrawnHeight) / 2);

                    overviewContext.clearRect(0, 0, cssWidth, desiredHeight);
                    overviewContext.imageSmoothingEnabled = false;
                    overviewContext.fillStyle = '#0f172a';
                    overviewContext.fillRect(minimapOriginX, minimapOriginY, minimapDrawnWidth, minimapDrawnHeight);

                    if (overviewImage !== null) {
                        overviewContext.drawImage(
                            overviewImage,
                            minimapOriginX,
                            minimapOriginY,
                            minimapDrawnWidth,
                            minimapDrawnHeight
                        );
                        overviewBaseReady = true;
                    } else {
                        overviewContext.fillStyle = '#111827';
                        overviewContext.fillRect(minimapOriginX, minimapOriginY, minimapDrawnWidth, minimapDrawnHeight);
                        overviewBaseReady = false;
                    }

                    overviewContext.strokeStyle = '#334155';
                    overviewContext.lineWidth = 1;
                    overviewContext.strokeRect(minimapOriginX + 0.5, minimapOriginY + 0.5, Math.max(0, minimapDrawnWidth - 1), Math.max(0, minimapDrawnHeight - 1));

                    const sourceOffsetX = offsetX * divisor;
                    const sourceOffsetY = offsetY * divisor;
                    const sourceViewportWidth = viewport.clientWidth * divisor;
                    const sourceViewportHeight = viewport.clientHeight * divisor;
                    const unclampedRectX = minimapOriginX + (sourceOffsetX * minimapScale);
                    const unclampedRectY = minimapOriginY + (sourceOffsetY * minimapScale);
                    const unclampedRectWidth = Math.max(6, sourceViewportWidth * minimapScale);
                    const unclampedRectHeight = Math.max(6, sourceViewportHeight * minimapScale);
                    const viewportRectX = Math.max(minimapOriginX, Math.min(minimapOriginX + minimapDrawnWidth, unclampedRectX));
                    const viewportRectY = Math.max(minimapOriginY, Math.min(minimapOriginY + minimapDrawnHeight, unclampedRectY));
                    const viewportRectMaxX = Math.max(viewportRectX, Math.min(minimapOriginX + minimapDrawnWidth, unclampedRectX + unclampedRectWidth));
                    const viewportRectMaxY = Math.max(viewportRectY, Math.min(minimapOriginY + minimapDrawnHeight, unclampedRectY + unclampedRectHeight));
                    const viewportRectWidth = Math.max(1, viewportRectMaxX - viewportRectX);
                    const viewportRectHeight = Math.max(1, viewportRectMaxY - viewportRectY);
                    overviewContext.fillStyle = 'rgba(16, 185, 129, 0.18)';
                    overviewContext.fillRect(viewportRectX, viewportRectY, viewportRectWidth, viewportRectHeight);
                    overviewContext.strokeStyle = '#10b981';
                    overviewContext.lineWidth = 1.2;
                    overviewContext.strokeRect(viewportRectX + 0.5, viewportRectY + 0.5, Math.max(0, viewportRectWidth - 1), Math.max(0, viewportRectHeight - 1));

                    overviewMapState = {
                        originX: minimapOriginX,
                        originY: minimapOriginY,
                        scaleX: minimapScale,
                        scaleY: minimapScale,
                        mapWidth: minimapSourceWidth,
                        mapHeight: minimapSourceHeight,
                        offsetScaleX: 1 / divisor,
                        offsetScaleY: 1 / divisor,
                    };
                    overviewStatusEl.textContent = overviewBaseReady
                        ? `View ${Math.round(offsetX)},${Math.round(offsetY)} @ z${zoom}`
                        : 'Loading...';
                    return;
                }

                if (overviewBaseCacheKey !== overviewKey || !overviewBaseReady) {
                    overviewBaseCanvas.width = cssWidth;
                    overviewBaseCanvas.height = desiredHeight;
                    overviewBaseContext.clearRect(0, 0, cssWidth, desiredHeight);
                    overviewBaseContext.imageSmoothingEnabled = false;
                    overviewBaseContext.fillStyle = '#0f172a';
                    overviewBaseContext.fillRect(originX, originY, drawnWidth, drawnHeight);
                    overviewBaseContext.fillStyle = '#111827';
                    overviewBaseContext.fillRect(originX, originY, drawnWidth, drawnHeight);
                    overviewBaseCacheKey = overviewKey;
                    overviewBaseReady = false;
                }

                overviewContext.clearRect(0, 0, cssWidth, desiredHeight);
                overviewContext.drawImage(overviewBaseCanvas, 0, 0);
                overviewContext.strokeStyle = '#334155';
                overviewContext.lineWidth = 1;
                overviewContext.strokeRect(originX + 0.5, originY + 0.5, Math.max(0, drawnWidth - 1), Math.max(0, drawnHeight - 1));

                const overviewScaleX = drawnWidth / Math.max(1, info.width);
                const overviewScaleY = drawnHeight / Math.max(1, info.height);
                const viewportRectX = originX + (offsetX * overviewScaleX);
                const viewportRectY = originY + (offsetY * overviewScaleY);
                const viewportRectWidth = Math.max(6, viewport.clientWidth * overviewScaleX);
                const viewportRectHeight = Math.max(6, viewport.clientHeight * overviewScaleY);

                overviewContext.fillStyle = 'rgba(16, 185, 129, 0.18)';
                overviewContext.fillRect(viewportRectX, viewportRectY, viewportRectWidth, viewportRectHeight);
                overviewContext.strokeStyle = '#10b981';
                overviewContext.lineWidth = 1.2;
                overviewContext.strokeRect(viewportRectX + 0.5, viewportRectY + 0.5, Math.max(0, viewportRectWidth - 1), Math.max(0, viewportRectHeight - 1));

                overviewMapState = {
                    originX,
                    originY,
                    scaleX: overviewScaleX,
                    scaleY: overviewScaleY,
                    mapWidth: info.width,
                    mapHeight: info.height,
                    offsetScaleX: 1,
                    offsetScaleY: 1,
                };
                overviewStatusEl.textContent = overviewBaseReady
                    ? `View ${Math.round(offsetX)},${Math.round(offsetY)} @ z${zoom}`
                    : 'Loading...';
            }

            function panFromOverview(clientX, clientY) {
                if (!overviewMapState || !manifest) {
                    return;
                }

                const rect = overviewCanvas.getBoundingClientRect();
                const localX = clientX - rect.left;
                const localY = clientY - rect.top;
                const clampedX = Math.max(
                    overviewMapState.originX,
                    Math.min(overviewMapState.originX + (overviewMapState.mapWidth * overviewMapState.scaleX), localX)
                );
                const clampedY = Math.max(
                    overviewMapState.originY,
                    Math.min(overviewMapState.originY + (overviewMapState.mapHeight * overviewMapState.scaleY), localY)
                );
                const mapX = (clampedX - overviewMapState.originX) / overviewMapState.scaleX;
                const mapY = (clampedY - overviewMapState.originY) / overviewMapState.scaleY;
                offsetX = Math.round((mapX * overviewMapState.offsetScaleX) - (viewport.clientWidth / 2));
                offsetY = Math.round((mapY * overviewMapState.offsetScaleY) - (viewport.clientHeight / 2));
                clampOffsets();
                requestRenderTiles();
            }

            function renderTiles() {
                if (!manifest) {
                    return;
                }

                const renderStartedAt = performance.now();
                const info = levelInfo(zoom);
                if (!info) {
                    return;
                }

                clampOffsets();

                if (selectedProjection === 'isometric') {
                    ensureIsometricCanvasSize();

                    const layers = Array.isArray(manifest?.image_layers) ? manifest.image_layers : [];
                    const divisor = mapDivisor(zoom);
                    let drawnLayerCount = 0;
                    let visibleLayerCount = 0;
                    let prefetchedLayers = 0;
                    let textureUploadsThisFrame = 0;
                    let hasPendingVisibleTextures = false;
                    let visibleLayerRequestsThisFrame = 0;
                    const maxVisibleRequestsThisFrame = isDragging
                        ? maxVisibleLayerRequestsWhileDragging
                        : maxVisibleLayerRequestsPerFrame;
                    const maxTextureUploadsThisFrame = isDragging
                        ? maxTextureUploadsWhileDragging
                        : maxWebglTextureUploadsPerFrame;

                    if (usingWebglIsometric && webglContext !== null) {
                        initializeWebglRenderer();

                        if (
                            webglProgram === null
                            || webglBuffer === null
                            || webglResolutionUniform === null
                            || webglTextureUniform === null
                            || webglPositionAttribute < 0
                            || webglTexCoordAttribute < 0
                        ) {
                            return;
                        }

                        webglContext.viewport(0, 0, isometricCanvas.width, isometricCanvas.height);
                        webglContext.clearColor(0, 0, 0, 0);
                        webglContext.clear(webglContext.COLOR_BUFFER_BIT);
                        webglContext.useProgram(webglProgram);
                        webglContext.bindBuffer(webglContext.ARRAY_BUFFER, webglBuffer);
                        webglContext.enableVertexAttribArray(webglPositionAttribute);
                        webglContext.enableVertexAttribArray(webglTexCoordAttribute);
                        webglContext.vertexAttribPointer(webglPositionAttribute, 2, webglContext.FLOAT, false, 16, 0);
                        webglContext.vertexAttribPointer(webglTexCoordAttribute, 2, webglContext.FLOAT, false, 16, 8);
                        webglContext.uniform2f(webglResolutionUniform, isometricCanvasWidth, isometricCanvasHeight);
                        webglContext.uniform1i(webglTextureUniform, 0);
                    } else if (isometricContext !== null) {
                        isometricContext.clearRect(0, 0, isometricCanvasWidth, isometricCanvasHeight);
                        isometricContext.imageSmoothingEnabled = false;
                    } else {
                        return;
                    }

                    for (const layer of layers) {
                        const cacheKey = `${layer.file}:${layer.url}`;
                        const cacheEntry = isometricLayerCache.get(cacheKey);
                        const layerX = Math.floor((layer.offset_x ?? 0) / divisor);
                        const layerY = Math.floor((layer.offset_y ?? 0) / divisor);
                        const layerWidth = Math.max(1, Math.ceil((layer.width ?? 0) / divisor));
                        const layerHeight = Math.max(1, Math.ceil((layer.height ?? 0) / divisor));
                        const drawX = layerX - offsetX;
                        const drawY = layerY - offsetY;

                        const layerRight = drawX + layerWidth;
                        const layerBottom = drawY + layerHeight;
                        const intersectsViewport = layerRight >= 0
                            && layerBottom >= 0
                            && drawX <= viewport.clientWidth
                            && drawY <= viewport.clientHeight;

                        if (intersectsViewport) {
                            visibleLayerCount++;
                        }

                        if (!cacheEntry) {
                            continue;
                        }

                        if (intersectsViewport) {
                            if (
                                !cacheEntry.loaded
                                && !cacheEntry.failed
                                && !cacheEntry.requested
                                && visibleLayerRequestsThisFrame < maxVisibleRequestsThisFrame
                            ) {
                                requestIsometricLayerImage(cacheEntry, true);
                                visibleLayerRequestsThisFrame++;
                            }
                        } else if (!isDragging && !cacheEntry.loaded && !cacheEntry.failed && prefetchedLayers < 1) {
                            requestIsometricLayerImage(cacheEntry, false);
                            prefetchedLayers++;
                        }

                        if (!intersectsViewport || cacheEntry.failed || !cacheEntry.loaded) {
                            continue;
                        }

                        const clipX = Math.max(0, drawX);
                        const clipY = Math.max(0, drawY);
                        const clipX2 = Math.min(viewport.clientWidth, drawX + layerWidth);
                        const clipY2 = Math.min(viewport.clientHeight, drawY + layerHeight);
                        const clipWidth = clipX2 - clipX;
                        const clipHeight = clipY2 - clipY;

                        if (clipWidth <= 0 || clipHeight <= 0) {
                            continue;
                        }

                        const normalizedClipX = (clipX - drawX) / layerWidth;
                        const normalizedClipY = (clipY - drawY) / layerHeight;
                        const normalizedClipX2 = (clipX + clipWidth - drawX) / layerWidth;
                        const normalizedClipY2 = (clipY + clipHeight - drawY) / layerHeight;

                        if (usingWebglIsometric && webglContext !== null) {
                            if (!cacheEntry.texture) {
                                if (textureUploadsThisFrame >= maxTextureUploadsThisFrame) {
                                    hasPendingVisibleTextures = true;
                                    continue;
                                }

                                const textureSource = cacheEntry.bitmap ?? cacheEntry.image ?? null;
                                if (!textureSource) {
                                    continue;
                                }

                                cacheEntry.texture = createWebglTextureFromSource(textureSource);
                                if (cacheEntry.texture === null && cacheEntry.bitmap !== null && cacheEntry.image === null) {
                                    loadIsometricLayerWithImage(cacheEntry, true);
                                    hasPendingVisibleTextures = true;
                                    continue;
                                }
                                textureUploadsThisFrame++;
                            }

                            if (!cacheEntry.texture) {
                                continue;
                            }

                            const x1 = clipX;
                            const y1 = clipY;
                            const x2 = clipX + clipWidth;
                            const y2 = clipY + clipHeight;
                            const tx1 = normalizedClipX;
                            const ty1 = normalizedClipY;
                            const tx2 = normalizedClipX2;
                            const ty2 = normalizedClipY2;
                            const vertices = new Float32Array([
                                x1, y1, tx1, ty1,
                                x2, y1, tx2, ty1,
                                x1, y2, tx1, ty2,
                                x1, y2, tx1, ty2,
                                x2, y1, tx2, ty1,
                                x2, y2, tx2, ty2,
                            ]);

                            webglContext.activeTexture(webglContext.TEXTURE0);
                            webglContext.bindTexture(webglContext.TEXTURE_2D, cacheEntry.texture);
                            webglContext.bufferData(webglContext.ARRAY_BUFFER, vertices, webglContext.STREAM_DRAW);
                            webglContext.drawArrays(webglContext.TRIANGLES, 0, 6);
                        } else if (isometricContext !== null) {
                            const drawable = cacheEntry.bitmap ?? cacheEntry.image ?? null;
                            const sourceWidthPx = drawable
                                ? (Number(drawable.width ?? 0) || Number(drawable.naturalWidth ?? 0))
                                : 0;
                            const sourceHeightPx = drawable
                                ? (Number(drawable.height ?? 0) || Number(drawable.naturalHeight ?? 0))
                                : 0;

                            if (!drawable || sourceWidthPx <= 0 || sourceHeightPx <= 0) {
                                continue;
                            }

                            const sourceX = Math.floor(normalizedClipX * sourceWidthPx);
                            const sourceY = Math.floor(normalizedClipY * sourceHeightPx);
                            const sourceWidth = Math.max(1, Math.ceil((normalizedClipX2 - normalizedClipX) * sourceWidthPx));
                            const sourceHeight = Math.max(1, Math.ceil((normalizedClipY2 - normalizedClipY) * sourceHeightPx));

                            isometricContext.drawImage(
                                drawable,
                                sourceX,
                                sourceY,
                                sourceWidth,
                                sourceHeight,
                                clipX,
                                clipY,
                                clipWidth,
                                clipHeight
                            );
                        }

                        drawnLayerCount++;
                    }

                    zoomEl.textContent = `${zoom} / ${uiMaxZoom()}`;
                    tilesEl.textContent = `${info.width} x ${info.height}`;
                    visibleTilesEl.textContent = `${drawnLayerCount}/${visibleLayerCount} layers`;
                    renderMsEl.textContent = `${Math.round(performance.now() - renderStartedAt)}`;
                    if (isDragging) {
                        overviewRefreshPending = true;
                    } else {
                        renderOverviewMap();
                        overviewRefreshPending = false;
                    }
                    scheduleUrlStateSync();

                    if (hasPendingVisibleTextures) {
                        requestRenderTiles();
                    }

                    return;
                }

                ensureBirdsEyeCanvasSize();
                clearBirdsEyeCanvas();
                syncBirdsEyeLayerCache();

                const layers = Array.isArray(manifest?.image_layers) ? manifest.image_layers : [];
                const divisor = mapDivisor(zoom);
                let visibleLayerCount = 0;
                let drawnLayerCount = 0;
                let pendingLayerCount = 0;

                if (birdsEyeContext === null) {
                    return;
                }

                birdsEyeContext.imageSmoothingEnabled = false;

                for (const layer of layers) {
                    const cacheKey = `${layer.file}:${layer.url}`;
                    const cacheEntry = birdsEyeLayerCache.get(cacheKey);
                    const layerLeft = ((layer.offset_x ?? 0) / divisor) - offsetX;
                    const layerTop = ((layer.offset_y ?? 0) / divisor) - offsetY;
                    const layerRight = (((layer.offset_x ?? 0) + (layer.width ?? 0)) / divisor) - offsetX;
                    const layerBottom = (((layer.offset_y ?? 0) + (layer.height ?? 0)) / divisor) - offsetY;
                    const intersectsViewport = layerRight >= 0
                        && layerBottom >= 0
                        && layerLeft <= viewport.clientWidth
                        && layerTop <= viewport.clientHeight;

                    if (!intersectsViewport) {
                        continue;
                    }

                    visibleLayerCount++;

                    if (!cacheEntry) {
                        continue;
                    }

                    if (!cacheEntry.loaded && !cacheEntry.failed && !cacheEntry.requested) {
                        requestBirdsEyeLayerImage(cacheEntry, true);
                    }

                    if (!cacheEntry.loaded || cacheEntry.failed) {
                        if (!cacheEntry.failed) {
                            pendingLayerCount++;
                        }

                        continue;
                    }

                    const clipX = Math.max(0, Math.floor(layerLeft));
                    const clipY = Math.max(0, Math.floor(layerTop));
                    const clipX2 = Math.min(viewport.clientWidth, Math.ceil(layerRight));
                    const clipY2 = Math.min(viewport.clientHeight, Math.ceil(layerBottom));
                    const clipWidth = clipX2 - clipX;
                    const clipHeight = clipY2 - clipY;

                    if (clipWidth <= 0 || clipHeight <= 0) {
                        continue;
                    }

                    const drawable = cacheEntry.bitmap ?? cacheEntry.image ?? null;
                    if (!drawable) {
                        pendingLayerCount++;
                        continue;
                    }

                    const seamOverlapPx = 0.75;
                    const drawLeft = layerLeft - seamOverlapPx;
                    const drawTop = layerTop - seamOverlapPx;
                    const drawWidth = (layerRight - layerLeft) + (seamOverlapPx * 2);
                    const drawHeight = (layerBottom - layerTop) + (seamOverlapPx * 2);
                    birdsEyeContext.save();
                    birdsEyeContext.beginPath();
                    birdsEyeContext.rect(clipX, clipY, clipWidth, clipHeight);
                    birdsEyeContext.clip();
                    birdsEyeContext.drawImage(
                        drawable,
                        drawLeft,
                        drawTop,
                        drawWidth,
                        drawHeight
                    );
                    birdsEyeContext.restore();

                    drawnLayerCount++;
                }

                zoomEl.textContent = `${zoom} / ${uiMaxZoom()}`;
                tilesEl.textContent = `${info.width} x ${info.height}`;
                visibleTilesEl.textContent = `${drawnLayerCount}/${visibleLayerCount} layers`;
                renderMsEl.textContent = `${Math.round(performance.now() - renderStartedAt)}`;
                renderOverviewMap();
                scheduleUrlStateSync();

                if (pendingLayerCount > 0) {
                    requestRenderTiles();
                }
            }

            function fitInitialPosition() {
                const viewportWidth = viewport.clientWidth;
                const viewportHeight = viewport.clientHeight;
                if (requestedViewState && requestedViewState.zoom >= uiMinZoom() && requestedViewState.zoom <= uiMaxZoom()) {
                    zoom = requestedViewState.zoom;
                    offsetX = requestedViewState.x;
                    offsetY = requestedViewState.y;
                    requestedViewState = null;
                    clampOffsets();
                    return;
                }

                let candidate = uiMaxZoom();
                while (candidate > uiMinZoom()) {
                    const info = levelInfo(candidate);
                    if (info.width <= viewportWidth * 1.4 && info.height <= viewportHeight * 1.4) {
                        break;
                    }
                    candidate--;
                }

                zoom = candidate;
                const info = levelInfo(zoom);
                offsetX = Math.max(0, Math.floor((info.width - viewportWidth) / 2));
                offsetY = Math.max(0, Math.floor((info.height - viewportHeight) / 2));
            }

            function zoomAtPointer(direction, clientX, clientY) {
                const nextZoom = Math.max(uiMinZoom(), Math.min(uiMaxZoom(), zoom + direction));

                if (nextZoom === zoom) {
                    return;
                }

                const rect = viewport.getBoundingClientRect();
                const pointerX = clientX - rect.left;
                const pointerY = clientY - rect.top;
                const currentDivisor = mapDivisor(zoom);
                const targetDivisor = mapDivisor(nextZoom);
                const worldX = (offsetX + pointerX) * currentDivisor;
                const worldY = (offsetY + pointerY) * currentDivisor;

                zoom = nextZoom;
                offsetX = Math.floor(worldX / targetDivisor - pointerX);
                offsetY = Math.floor(worldY / targetDivisor - pointerY);
                resetActiveTiles();
                requestRenderTiles();
            }

            async function loadManifest(options = {}) {
                const manifestStartedAt = performance.now();
                const preserveView = options.preserveView === true;
                const refresh = options.refresh !== false;
                const preserveWorldCenter = options.preserveWorldCenter ?? null;
                setStatus('Loading map manifest...');
                const hadManifest = manifest !== null;
                const query = buildQueryString();
                const response = await fetch(`/api/maps/manifest?${query}&include_regions=0&refresh=${refresh ? '1' : '0'}`);
                const payload = await response.json();

                if (!payload.available) {
                    manifest = null;
                    stopIsometricOverviewPreload();
                    resetActiveTiles();
                    setStatus(payload.message ?? 'Map not available.');
                    manifestMsEl.textContent = `${Math.round(performance.now() - manifestStartedAt)}`;
                    scheduleUrlStateSync();
                    return;
                }

                manifest = payload.manifest;
                overviewBaseCacheKey = '';
                overviewBaseReady = false;
                syncProjectionLayers();
                const nextCacheKey = `${selectedProjection}:${manifest.generated_at ?? ''}`;
                if (selectedProjection === 'isometric' && isometricCacheKey !== nextCacheKey) {
                    isometricCacheKey = nextCacheKey;
                    isometricOverviewLayerVersion = 0;
                    syncIsometricLayerCache();
                    scheduleIsometricOverviewPreload();
                } else if (selectedProjection !== 'isometric') {
                    stopIsometricOverviewPreload();
                }
                syncOverviewImage();
                if (preserveView && hadManifest) {
                    zoom = Math.max(uiMinZoom(), Math.min(zoom, uiMaxZoom()));
                    applyViewportWorldCenter(preserveWorldCenter);
                    clampOffsets();
                } else {
                    fitInitialPosition();
                }

                resetActiveTiles();
                if (selectedProjection === 'birds-eye') {
                    syncBirdsEyeLayerCache();
                }
                requestRenderTiles();
                setStatus(`Map loaded (${selectedProjection}, full): ${manifest.source_width} x ${manifest.source_height} blocks`);
                manifestMsEl.textContent = `${Math.round(performance.now() - manifestStartedAt)}`;
                scheduleUrlStateSync();
            }

            async function pollBatchUntilFinished(batchId) {
                stopBatchPolling();
                activeBatchId = batchId;
                let pollIteration = 0;

                const poll = async () => {
                    if (activeBatchId !== batchId) {
                        return;
                    }

                    try {
                        const response = await fetch(`/api/maps/batches/${batchId}`);

                        if (!response.ok) {
                            throw new Error('Unable to read queue batch progress.');
                        }

                        const payload = await response.json();
                        pollIteration++;
                        pollCount++;
                        pollCountEl.textContent = String(pollCount);
                        const shouldRefreshManifest = selectedProjection === 'isometric'
                            ? pollIteration % 2 === 0
                            : pollIteration % 4 === 0;
                        if (payload.finished === true || shouldRefreshManifest) {
                            await loadManifest({
                                preserveView: true,
                                refresh: shouldRefreshManifest || payload.finished === true,
                            });
                        }
                        const processedJobs = payload.processed_jobs ?? 0;
                        const totalJobs = payload.total_jobs ?? 0;
                        const failedJobs = payload.failed_jobs ?? 0;

                        if (payload.finished === true) {
                            setStatus(`Batch ${batchId} complete (${processedJobs}/${totalJobs}, failed: ${failedJobs}).`);
                            stopBatchPolling();
                            return;
                        }

                        setStatus(`Batch ${batchId}: ${processedJobs}/${totalJobs} jobs finished, failed: ${failedJobs}.`);
                        batchPollTimer = window.setTimeout(poll, 1200);
                    } catch (error) {
                        setStatus(error.message);
                        batchPollTimer = window.setTimeout(poll, 2000);
                    }
                };

                await poll();
            }

            async function renderWorldMap() {
                setStatus('Queueing map generation jobs...');
                renderButton.disabled = true;

                try {
                    const token = document.querySelector('meta[name="csrf-token"]').content;
                    const endpoint = selectedProjection === 'isometric'
                        ? '/api/maps/isometric/render'
                        : '/api/maps/birdeye/render';
                    const priorityPayload = viewportWorldBounds() ?? {};
                    if (selectedProjection === 'isometric') {
                        priorityPayload.priority_regions = visibleIsometricRegionFiles();
                    }
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            heightmap: 'WORLD_SURFACE',
                            ...priorityPayload,
                        }),
                    });

                    if (!response.ok) {
                        const payload = await response.json();
                        throw new Error(payload.message ?? 'Render failed.');
                    }

                    const payload = await response.json();
                    const queuedRegions = payload.region_count ?? 0;
                    const batchId = payload.batch_id ?? '';

                    if (queuedRegions === 0 || batchId === '') {
                        setStatus(payload.message ?? 'No changed regions detected.');
                        await loadManifest({
                            preserveView: true,
                            refresh: true,
                        });

                        return;
                    }

                    setStatus(`Queued ${queuedRegions} ${selectedProjection} region jobs (batch ${batchId}).`);
                    await pollBatchUntilFinished(batchId);
                } catch (error) {
                    setStatus(error.message);
                } finally {
                    renderButton.disabled = false;
                }
            }

            viewport.addEventListener('mousedown', event => {
                isDragging = true;
                stopIsometricOverviewPreload();
                dragStartX = event.clientX;
                dragStartY = event.clientY;
                dragOriginX = offsetX;
                dragOriginY = offsetY;
            });

            window.addEventListener('mouseup', () => {
                const wasDragging = isDragging;
                isDragging = false;

                if (wasDragging) {
                    scheduleIsometricOverviewPreload();

                    if (overviewRefreshPending) {
                        requestRenderTiles();
                    }
                }
            });

            window.addEventListener('mousemove', event => {
                scheduleCursorReadout(event.clientX, event.clientY);

                if (!isDragging || !manifest) {
                    return;
                }

                offsetX = dragOriginX - (event.clientX - dragStartX);
                offsetY = dragOriginY - (event.clientY - dragStartY);
                requestRenderTiles();
            });

            viewport.addEventListener('wheel', event => {
                if (!manifest) {
                    return;
                }

                event.preventDefault();
                zoomAtPointer(event.deltaY < 0 ? 1 : -1, event.clientX, event.clientY);
            }, { passive: false });

            overviewCanvas.addEventListener('mousedown', event => {
                overviewDragging = true;
                panFromOverview(event.clientX, event.clientY);
            });

            window.addEventListener('mouseup', () => {
                overviewDragging = false;
            });

            window.addEventListener('mousemove', event => {
                if (!overviewDragging) {
                    return;
                }

                panFromOverview(event.clientX, event.clientY);
            });

            overviewCanvas.addEventListener('click', event => {
                panFromOverview(event.clientX, event.clientY);
            });

            window.addEventListener('resize', () => {
                if (manifest) {
                    if (selectedProjection === 'isometric') {
                        ensureIsometricCanvasSize();
                    }
                    requestRenderTiles();
                }
            });

            renderButton.addEventListener('click', renderWorldMap);
            projectionSelect.addEventListener('change', () => {
                stopBatchPolling();
                const preserveWorldCenter = currentViewportWorldCenter();
                selectedProjection = projectionSelect.value || 'birds-eye';
                resetActiveTiles();
                syncProjectionLayers();
                scheduleUrlStateSync();
                loadManifest({
                    preserveView: true,
                    preserveWorldCenter,
                    refresh: true,
                }).catch(error => setStatus(error.message));
            });

            window.addEventListener('beforeunload', () => {
                stopIsometricOverviewPreload();

                if (isometricLayerLoaderWorker !== null) {
                    isometricLayerLoaderWorker.terminate();
                    isometricLayerLoaderWorker = null;
                }

                if (isometricLayerLoaderWorkerUrl !== null) {
                    URL.revokeObjectURL(isometricLayerLoaderWorkerUrl);
                    isometricLayerLoaderWorkerUrl = null;
                }
            });

            loadManifest({
                preserveView: false,
                refresh: true,
            }).catch(error => setStatus(error.message));
            syncProjectionLayers();
        </script>
    </body>
</html>

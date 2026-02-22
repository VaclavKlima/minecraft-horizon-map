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

                        <select
                            id="region-select"
                            class="rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none"
                        >
                            <option value="all">All regions</option>
                        </select>

                        <button
                            id="render-map"
                            type="button"
                            class="rounded-md bg-emerald-500 px-3 py-2 text-sm font-semibold text-slate-900 transition hover:bg-emerald-400"
                        >
                            Queue Tile Jobs
                        </button>
                    </div>
                </div>
            </header>

            <main class="relative overflow-hidden">
                <div id="map-viewport" class="absolute inset-0 cursor-grab overflow-hidden bg-slate-950 active:cursor-grabbing">
                    <canvas id="isometric-canvas" class="absolute inset-0 hidden"></canvas>
                    <div id="tile-layer" class="absolute inset-0"></div>
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
            const regionSelect = document.getElementById('region-select');
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
            let activeTiles = new Map();
            let selectedRegion = null;
            let selectedProjection = 'birds-eye';
            let requestedViewState = null;
            let urlSyncTimeout = null;
            let activeBatchId = null;
            let batchPollTimer = null;
            let renderFrameRequested = false;
            let cursorFrameRequested = false;
            let lastPointerClientX = 0;
            let lastPointerClientY = 0;
            let renderedRegionOptionsKey = '';
            let pollCount = 0;
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
            const overviewBirdsEyeTileCache = new Map();
            let overviewBaseCacheKey = '';
            let overviewBaseReady = false;
            let isometricOverviewLayerVersion = 0;
            let isometricOverviewPreloadTimer = null;
            let overviewRefreshPending = false;

            const isometricLayerCache = new Map();

            const tileSize = 256;
            const preloadTileMargin = 1;
            const maxIsometricOverzoomLevels = 2;
            const maxWebglTextureUploadsPerFrame = 2;
            const maxVisibleLayerRequestsPerFrame = 2;
            const usingWebglIsometric = webglContext !== null;
            const urlParams = new URLSearchParams(window.location.search);

            if (urlParams.get('projection') === 'isometric') {
                selectedProjection = 'isometric';
            }

            const requestedRegion = urlParams.get('region');
            if (requestedRegion !== null && requestedRegion !== '') {
                selectedRegion = requestedRegion;
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

            function availableZoomLevels() {
                const levels = Array.isArray(manifest?.available_levels)
                    ? manifest.available_levels
                        .map(value => Number.parseInt(String(value), 10))
                        .filter(Number.isInteger)
                        .sort((left, right) => left - right)
                    : [];

                return levels;
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

                if (selectedRegion) {
                    params.set('region', selectedRegion);
                }

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

            function maxStaticZoomLevel() {
                const levels = availableZoomLevels();

                if (levels.length === 0) {
                    return -1;
                }

                return levels[levels.length - 1];
            }

            function clampOffsets() {
                const info = levelInfo(zoom);
                const maxOffsetX = Math.max(0, info.width - viewport.clientWidth);
                const maxOffsetY = Math.max(0, info.height - viewport.clientHeight);

                offsetX = Math.max(0, Math.min(maxOffsetX, offsetX));
                offsetY = Math.max(0, Math.min(maxOffsetY, offsetY));
            }

            function setStatus(message) {
                statusEl.textContent = message;
            }

            function buildQueryString(includeRegion = true) {
                const params = new URLSearchParams();

                if (includeRegion && selectedRegion) {
                    params.set('region', selectedRegion);
                }

                params.set('projection', selectedProjection);

                return params.toString();
            }

            function regionKeyForPath(regionFile) {
                return String(regionFile ?? 'all').replaceAll('.', '_').replaceAll('/', '_');
            }

            function tileBasePath() {
                const regionKey = regionKeyForPath(manifest?.selected_region ?? selectedRegion ?? 'all');

                return `/maps/tiles/${regionKey}`;
            }

            function tileUrl(level, x, y) {
                const params = new URLSearchParams();

                if (manifest?.generated_at) {
                    params.set('t', String(manifest.generated_at));
                }

                if (level <= maxStaticZoomLevel()) {
                    return `${tileBasePath()}/${level}/${x}/${y}.png?${params.toString()}`;
                }

                params.set('projection', selectedProjection);

                if (selectedRegion) {
                    params.set('region', selectedRegion);
                }

                return `/api/maps/tiles/${level}/${x}/${y}.png?${params.toString()}`;
            }

            function apiTileUrl(level, x, y) {
                const params = new URLSearchParams();

                if (manifest?.generated_at) {
                    params.set('t', String(manifest.generated_at));
                }

                params.set('projection', selectedProjection);

                if (selectedRegion) {
                    params.set('region', selectedRegion);
                }

                return `/api/maps/tiles/${level}/${x}/${y}.png?${params.toString()}`;
            }

            function resetActiveTiles() {
                activeTiles.forEach(tile => tile.remove());
                activeTiles.clear();
                clearIsometricCanvas();
            }

            function syncProjectionLayers() {
                const isometricMode = selectedProjection === 'isometric';
                isometricCanvas.classList.toggle('hidden', !isometricMode);
                tileLayer.classList.toggle('hidden', isometricMode);
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

            function createWebglTextureFromImage(image) {
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
                    image
                );

                return texture;
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

                    const image = new Image();
                    image.decoding = 'async';
                    const entry = {
                        image,
                        loaded: false,
                        failed: false,
                        texture: null,
                        requested: false,
                        url: layer.url,
                    };
                    image.onload = () => {
                        entry.loaded = true;
                        isometricOverviewLayerVersion++;
                        overviewBaseCacheKey = '';
                        overviewBaseReady = false;
                        if (!isDragging) {
                            scheduleIsometricOverviewPreload();
                        }
                        requestRenderTiles();
                    };
                    image.onerror = () => {
                        entry.failed = true;
                        isometricOverviewLayerVersion++;
                        overviewBaseCacheKey = '';
                        overviewBaseReady = false;
                        if (!isDragging) {
                            scheduleIsometricOverviewPreload();
                        }
                    };
                    isometricLayerCache.set(key, entry);
                }

                for (const existingKey of isometricLayerCache.keys()) {
                    if (!nextKeys.has(existingKey)) {
                        const staleEntry = isometricLayerCache.get(existingKey);
                        if (usingWebglIsometric && webglContext !== null && staleEntry?.texture) {
                            webglContext.deleteTexture(staleEntry.texture);
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
                cacheEntry.image.loading = highPriority ? 'eager' : 'lazy';
                cacheEntry.image.fetchPriority = highPriority ? 'high' : 'low';
                cacheEntry.image.src = cacheEntry.url;
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
                const overviewKey = `${selectedProjection}:${selectedRegion ?? 'all'}:${manifest.generated_at ?? ''}:${cssWidth}:${desiredHeight}:${sourceMapWidth}:${sourceMapHeight}:${isometricOverviewLayerVersion}`;

                if (overviewBaseCacheKey !== overviewKey || !overviewBaseReady) {
                    overviewBaseCanvas.width = cssWidth;
                    overviewBaseCanvas.height = desiredHeight;
                    overviewBaseContext.clearRect(0, 0, cssWidth, desiredHeight);
                    overviewBaseContext.imageSmoothingEnabled = false;
                    overviewBaseContext.fillStyle = '#0f172a';
                    overviewBaseContext.fillRect(originX, originY, drawnWidth, drawnHeight);
                    let backgroundReady = false;

                    if (selectedProjection === 'isometric') {
                        const layers = Array.isArray(manifest?.image_layers) ? manifest.image_layers : [];
                        let drawnLayerCount = 0;

                        for (const layer of layers) {
                            const cacheKey = `${layer.file}:${layer.url}`;
                            const cacheEntry = isometricLayerCache.get(cacheKey);

                            if (!cacheEntry || !cacheEntry.loaded || cacheEntry.failed) {
                                continue;
                            }

                            const sourceLayerX = Number(layer.offset_x ?? 0);
                            const sourceLayerY = Number(layer.offset_y ?? 0);
                            const sourceLayerWidth = Math.max(1, Number(layer.width ?? 0));
                            const sourceLayerHeight = Math.max(1, Number(layer.height ?? 0));
                            const targetX = originX + ((sourceLayerX / sourceMapWidth) * drawnWidth);
                            const targetY = originY + ((sourceLayerY / sourceMapHeight) * drawnHeight);
                            const targetWidth = Math.max(1, (sourceLayerWidth / sourceMapWidth) * drawnWidth);
                            const targetHeight = Math.max(1, (sourceLayerHeight / sourceMapHeight) * drawnHeight);
                            overviewBaseContext.drawImage(cacheEntry.image, targetX, targetY, targetWidth, targetHeight);
                            drawnLayerCount++;
                        }

                        backgroundReady = drawnLayerCount > 0;
                    } else {
                        const minOverviewZoom = uiMinZoom();
                        const minOverviewInfo = levelInfo(minOverviewZoom);

                        if (minOverviewInfo && Number.isInteger(minOverviewInfo.tiles_x) && Number.isInteger(minOverviewInfo.tiles_y)) {
                            const minOverviewDivisor = mapDivisor(minOverviewZoom);
                            let drawnTileCount = 0;

                            for (let tileY = 0; tileY < minOverviewInfo.tiles_y; tileY++) {
                                for (let tileX = 0; tileX < minOverviewInfo.tiles_x; tileX++) {
                                    const key = `${selectedProjection}:${selectedRegion ?? 'all'}:${manifest.generated_at ?? ''}:${minOverviewZoom}:${tileX}:${tileY}`;
                                    let tileEntry = overviewBirdsEyeTileCache.get(key);

                                    if (!tileEntry) {
                                        const image = new Image();
                                        image.decoding = 'async';
                                        image.loading = 'eager';
                                        image.fetchPriority = 'low';
                                        tileEntry = { image, loaded: false, failed: false };
                                        image.onload = () => {
                                            tileEntry.loaded = true;
                                            requestRenderTiles();
                                        };
                                        image.onerror = () => {
                                            if (!image.dataset.apiFallback) {
                                                image.dataset.apiFallback = '1';
                                                image.src = apiTileUrl(minOverviewZoom, tileX, tileY);
                                                return;
                                            }

                                            tileEntry.failed = true;
                                        };
                                        image.src = tileUrl(minOverviewZoom, tileX, tileY);
                                        overviewBirdsEyeTileCache.set(key, tileEntry);
                                    }

                                    if (!tileEntry.loaded || tileEntry.failed) {
                                        continue;
                                    }

                                    const sourcePixelX = tileX * tileSize * minOverviewDivisor;
                                    const sourcePixelY = tileY * tileSize * minOverviewDivisor;
                                    const tilePixelWidth = Math.min(tileSize, Math.max(0, minOverviewInfo.width - (tileX * tileSize)));
                                    const tilePixelHeight = Math.min(tileSize, Math.max(0, minOverviewInfo.height - (tileY * tileSize)));
                                    const sourcePixelWidth = tilePixelWidth * minOverviewDivisor;
                                    const sourcePixelHeight = tilePixelHeight * minOverviewDivisor;
                                    const targetX = originX + ((sourcePixelX / sourceMapWidth) * drawnWidth);
                                    const targetY = originY + ((sourcePixelY / sourceMapHeight) * drawnHeight);
                                    const targetWidth = Math.max(1, (sourcePixelWidth / sourceMapWidth) * drawnWidth);
                                    const targetHeight = Math.max(1, (sourcePixelHeight / sourceMapHeight) * drawnHeight);
                                    overviewBaseContext.drawImage(tileEntry.image, targetX, targetY, targetWidth, targetHeight);
                                    drawnTileCount++;
                                }
                            }

                            backgroundReady = drawnTileCount > 0;
                        }
                    }

                    if (!backgroundReady) {
                        overviewBaseContext.fillStyle = '#111827';
                        overviewBaseContext.fillRect(originX, originY, drawnWidth, drawnHeight);
                    }

                    overviewBaseCacheKey = overviewKey;
                    overviewBaseReady = backgroundReady;
                }

                overviewContext.clearRect(0, 0, cssWidth, desiredHeight);
                overviewContext.drawImage(overviewBaseCanvas, 0, 0);
                overviewContext.strokeStyle = '#334155';
                overviewContext.lineWidth = 1;
                overviewContext.strokeRect(originX + 0.5, originY + 0.5, Math.max(0, drawnWidth - 1), Math.max(0, drawnHeight - 1));

                const sourceOffsetX = offsetX * divisor;
                const sourceOffsetY = offsetY * divisor;
                const sourceViewportWidth = viewport.clientWidth * divisor;
                const sourceViewportHeight = viewport.clientHeight * divisor;
                const viewportRectX = originX + (sourceOffsetX * scale);
                const viewportRectY = originY + (sourceOffsetY * scale);
                const viewportRectWidth = Math.max(6, sourceViewportWidth * scale);
                const viewportRectHeight = Math.max(6, sourceViewportHeight * scale);

                overviewContext.fillStyle = 'rgba(16, 185, 129, 0.18)';
                overviewContext.fillRect(viewportRectX, viewportRectY, viewportRectWidth, viewportRectHeight);
                overviewContext.strokeStyle = '#10b981';
                overviewContext.lineWidth = 1.2;
                overviewContext.strokeRect(viewportRectX + 0.5, viewportRectY + 0.5, Math.max(0, viewportRectWidth - 1), Math.max(0, viewportRectHeight - 1));

                overviewMapState = {
                    originX,
                    originY,
                    scale,
                    mapWidth: sourceMapWidth,
                    mapHeight: sourceMapHeight,
                    divisor,
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
                    Math.min(overviewMapState.originX + (overviewMapState.mapWidth * overviewMapState.scale), localX)
                );
                const clampedY = Math.max(
                    overviewMapState.originY,
                    Math.min(overviewMapState.originY + (overviewMapState.mapHeight * overviewMapState.scale), localY)
                );
                const mapX = (clampedX - overviewMapState.originX) / overviewMapState.scale;
                const mapY = (clampedY - overviewMapState.originY) / overviewMapState.scale;
                offsetX = Math.round((mapX / overviewMapState.divisor) - (viewport.clientWidth / 2));
                offsetY = Math.round((mapY / overviewMapState.divisor) - (viewport.clientHeight / 2));
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
                        ? 0
                        : maxVisibleLayerRequestsPerFrame;

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
                                if (isDragging) {
                                    hasPendingVisibleTextures = true;
                                    continue;
                                }

                                if (textureUploadsThisFrame >= maxWebglTextureUploadsPerFrame) {
                                    hasPendingVisibleTextures = true;
                                    continue;
                                }

                                cacheEntry.texture = createWebglTextureFromImage(cacheEntry.image);
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
                            const sourceX = Math.floor(normalizedClipX * cacheEntry.image.naturalWidth);
                            const sourceY = Math.floor(normalizedClipY * cacheEntry.image.naturalHeight);
                            const sourceWidth = Math.max(1, Math.ceil((normalizedClipX2 - normalizedClipX) * cacheEntry.image.naturalWidth));
                            const sourceHeight = Math.max(1, Math.ceil((normalizedClipY2 - normalizedClipY) * cacheEntry.image.naturalHeight));

                            isometricContext.drawImage(
                                cacheEntry.image,
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

                    if (hasPendingVisibleTextures && !isDragging) {
                        requestRenderTiles();
                    }

                    return;
                }

                const staticMaxZoom = maxStaticZoomLevel();
                const renderZoom = staticMaxZoom >= 0 ? Math.min(zoom, staticMaxZoom) : zoom;
                const renderInfo = levelInfo(renderZoom);

                if (!renderInfo) {
                    return;
                }

                const scale = 2 ** (zoom - renderZoom);
                const scaledTileSize = tileSize * scale;
                const sourceOffsetX = offsetX / scale;
                const sourceOffsetY = offsetY / scale;
                const visibleMinTileX = Math.floor(sourceOffsetX / tileSize);
                const visibleMinTileY = Math.floor(sourceOffsetY / tileSize);
                const visibleMaxTileX = Math.min(renderInfo.tiles_x - 1, Math.floor((sourceOffsetX + (viewport.clientWidth / scale)) / tileSize));
                const visibleMaxTileY = Math.min(renderInfo.tiles_y - 1, Math.floor((sourceOffsetY + (viewport.clientHeight / scale)) / tileSize));
                const minTileX = Math.max(0, visibleMinTileX - preloadTileMargin);
                const minTileY = Math.max(0, visibleMinTileY - preloadTileMargin);
                const maxTileX = Math.min(renderInfo.tiles_x - 1, visibleMaxTileX + preloadTileMargin);
                const maxTileY = Math.min(renderInfo.tiles_y - 1, visibleMaxTileY + preloadTileMargin);
                const nextKeys = new Set();
                let visibleTileCount = 0;

                for (let tileY = minTileY; tileY <= maxTileY; tileY++) {
                    for (let tileX = minTileX; tileX <= maxTileX; tileX++) {
                        const key = `${zoom}:${renderZoom}:${tileX}:${tileY}`;
                        const isVisible = tileX >= visibleMinTileX
                            && tileX <= visibleMaxTileX
                            && tileY >= visibleMinTileY
                            && tileY <= visibleMaxTileY;
                        nextKeys.add(key);

                        if (!activeTiles.has(key)) {
                            const img = new Image();
                            img.draggable = false;
                            img.src = tileUrl(renderZoom, tileX, tileY);
                            img.decoding = 'async';
                            img.loading = isVisible ? 'eager' : 'lazy';
                            img.fetchPriority = isVisible ? 'high' : 'low';
                            img.onerror = () => {
                                if (!img.dataset.apiFallback) {
                                    img.dataset.apiFallback = '1';
                                    img.src = apiTileUrl(renderZoom, tileX, tileY);

                                    return;
                                }
                            };
                            img.className = 'absolute h-64 w-64 select-none';
                            activeTiles.set(key, img);
                            tileLayer.appendChild(img);
                        }

                        const tile = activeTiles.get(key);
                        tile.loading = isVisible ? 'eager' : 'lazy';
                        tile.fetchPriority = isVisible ? 'high' : 'low';
                        tile.style.left = `${(tileX * scaledTileSize) - offsetX}px`;
                        tile.style.top = `${(tileY * scaledTileSize) - offsetY}px`;
                        tile.style.width = `${scaledTileSize}px`;
                        tile.style.height = `${scaledTileSize}px`;

                        if (isVisible) {
                            visibleTileCount++;
                        }
                    }
                }

                for (const [key, tile] of activeTiles.entries()) {
                    if (!nextKeys.has(key)) {
                        tile.remove();
                        activeTiles.delete(key);
                    }
                }

                zoomEl.textContent = `${zoom} / ${uiMaxZoom()} (src: ${renderZoom})`;
                tilesEl.textContent = `${info.tiles_x} x ${info.tiles_y}`;
                visibleTilesEl.textContent = String(visibleTileCount);
                renderMsEl.textContent = `${Math.round(performance.now() - renderStartedAt)}`;
                renderOverviewMap();
                scheduleUrlStateSync();
            }

            function renderRegionOptions() {
                const regions = manifest?.available_regions ?? [];
                const optionsKey = regions.join('|');

                if (optionsKey === renderedRegionOptionsKey) {
                    regionSelect.value = selectedRegion ?? 'all';
                    regionSelect.disabled = regions.length === 0;

                    return;
                }

                renderedRegionOptionsKey = optionsKey;
                regionSelect.innerHTML = '';
                const fragment = document.createDocumentFragment();

                for (const region of regions) {
                    const option = document.createElement('option');
                    option.value = region;
                    option.textContent = region === 'all' ? 'All regions' : region;
                    option.selected = region === selectedRegion;
                    fragment.appendChild(option);
                }

                regionSelect.appendChild(fragment);
                regionSelect.disabled = regions.length === 0;
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

            async function loadManifest(region = selectedRegion, options = {}) {
                const manifestStartedAt = performance.now();
                const preserveView = options.preserveView === true;
                const includeRegions = options.includeRegions !== false;
                const refresh = options.refresh !== false;
                setStatus('Loading map manifest...');
                selectedRegion = region || null;
                const hadManifest = manifest !== null;
                const query = buildQueryString(true);
                const response = await fetch(`/api/maps/manifest?${query}&include_regions=${includeRegions ? '1' : '0'}&refresh=${refresh ? '1' : '0'}`);
                const payload = await response.json();

                if (!payload.available) {
                    manifest = null;
                    selectedRegion = null;
                    stopIsometricOverviewPreload();
                    if (includeRegions) {
                        regionSelect.innerHTML = '<option value="">No regions</option>';
                        regionSelect.disabled = true;
                        renderedRegionOptionsKey = '';
                    }
                    resetActiveTiles();
                    setStatus(payload.message ?? 'Map not available.');
                    manifestMsEl.textContent = `${Math.round(performance.now() - manifestStartedAt)}`;
                    scheduleUrlStateSync();
                    return;
                }

                manifest = payload.manifest;
                selectedRegion = manifest.selected_region;
                const activeOverviewPrefix = `${selectedProjection}:${selectedRegion ?? 'all'}:${manifest.generated_at ?? ''}:`;
                for (const cacheKey of overviewBirdsEyeTileCache.keys()) {
                    if (!cacheKey.startsWith(activeOverviewPrefix)) {
                        overviewBirdsEyeTileCache.delete(cacheKey);
                    }
                }
                overviewBaseCacheKey = '';
                overviewBaseReady = false;
                syncProjectionLayers();
                const nextCacheKey = `${selectedProjection}:${selectedRegion}:${manifest.generated_at ?? ''}`;
                if (selectedProjection === 'isometric' && isometricCacheKey !== nextCacheKey) {
                    isometricCacheKey = nextCacheKey;
                    isometricOverviewLayerVersion = 0;
                    syncIsometricLayerCache();
                    scheduleIsometricOverviewPreload();
                } else if (selectedProjection !== 'isometric') {
                    stopIsometricOverviewPreload();
                }
                if (includeRegions) {
                    renderRegionOptions();
                }
                if (preserveView && hadManifest) {
                    zoom = Math.min(zoom, uiMaxZoom());
                    clampOffsets();
                } else {
                    fitInitialPosition();
                }

                resetActiveTiles();
                requestRenderTiles();
                setStatus(`Map loaded (${selectedProjection}, ${selectedRegion}): ${manifest.source_width} x ${manifest.source_height} blocks`);
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
                        const shouldRefreshManifest = selectedProjection === 'isometric' && selectedRegion === 'all'
                            ? pollIteration % 2 === 0
                            : pollIteration % 4 === 0;
                        if (payload.finished === true || shouldRefreshManifest) {
                            await loadManifest(selectedRegion, {
                                preserveView: true,
                                includeRegions: false,
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
                        await loadManifest(selectedRegion, {
                            preserveView: true,
                            includeRegions: false,
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
            regionSelect.addEventListener('change', () => {
                selectedRegion = regionSelect.value || null;
                resetActiveTiles();
                scheduleUrlStateSync();
                loadManifest(selectedRegion, {
                    preserveView: false,
                    includeRegions: true,
                    refresh: true,
                }).catch(error => setStatus(error.message));
            });

            projectionSelect.addEventListener('change', () => {
                stopBatchPolling();
                selectedProjection = projectionSelect.value || 'birds-eye';
                selectedRegion = null;
                resetActiveTiles();
                syncProjectionLayers();
                scheduleUrlStateSync();
                loadManifest(null, {
                    preserveView: false,
                    includeRegions: true,
                    refresh: true,
                }).catch(error => setStatus(error.message));
            });

            loadManifest(null, {
                preserveView: false,
                includeRegions: true,
                refresh: true,
            }).catch(error => setStatus(error.message));
            syncProjectionLayers();
        </script>
    </body>
</html>

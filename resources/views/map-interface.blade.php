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
                    <div id="tile-layer" class="absolute inset-0"></div>
                </div>

                <aside class="pointer-events-none absolute left-4 top-4 z-20 w-72 rounded-lg border border-slate-700/80 bg-slate-900/85 p-3 text-xs">
                    <p id="map-status" class="text-slate-300">Loading map manifest...</p>
                    <div class="mt-2 grid grid-cols-2 gap-1 text-slate-200">
                        <span>Zoom:</span><span id="map-zoom">-</span>
                        <span>Cursor X:</span><span id="map-cursor-x">-</span>
                        <span>Cursor Z:</span><span id="map-cursor-z">-</span>
                        <span>Tiles:</span><span id="map-tiles">-</span>
                    </div>
                </aside>
            </main>
        </div>

        <script>
            const viewport = document.getElementById('map-viewport');
            const tileLayer = document.getElementById('tile-layer');
            const statusEl = document.getElementById('map-status');
            const zoomEl = document.getElementById('map-zoom');
            const cursorXEl = document.getElementById('map-cursor-x');
            const cursorZEl = document.getElementById('map-cursor-z');
            const tilesEl = document.getElementById('map-tiles');
            const renderButton = document.getElementById('render-map');
            const regionSelect = document.getElementById('region-select');
            const projectionSelect = document.getElementById('projection-select');

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
            let tileRetryTimer = null;
            let activeBatchId = null;
            let batchPollTimer = null;

            const tileSize = 256;
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

            function tileUrl(level, x, y) {
                const params = new URLSearchParams(buildQueryString(true));

                if (manifest?.generated_at) {
                    params.set('t', String(manifest.generated_at));
                }

                return `/api/maps/tiles/${level}/${x}/${y}.png?${params.toString()}`;
            }

            function resetActiveTiles() {
                activeTiles.forEach(tile => tile.remove());
                activeTiles.clear();
            }

            function stopBatchPolling() {
                activeBatchId = null;

                if (batchPollTimer !== null) {
                    window.clearTimeout(batchPollTimer);
                    batchPollTimer = null;
                }
            }

            function renderTiles() {
                if (!manifest) {
                    return;
                }

                const info = levelInfo(zoom);
                clampOffsets();

                const minTileX = Math.floor(offsetX / tileSize);
                const minTileY = Math.floor(offsetY / tileSize);
                const maxTileX = Math.min(info.tiles_x - 1, Math.floor((offsetX + viewport.clientWidth) / tileSize));
                const maxTileY = Math.min(info.tiles_y - 1, Math.floor((offsetY + viewport.clientHeight) / tileSize));
                const nextKeys = new Set();

                for (let tileY = minTileY; tileY <= maxTileY; tileY++) {
                    for (let tileX = minTileX; tileX <= maxTileX; tileX++) {
                        const key = `${zoom}:${tileX}:${tileY}`;
                        nextKeys.add(key);

                        if (!activeTiles.has(key)) {
                            const img = new Image();
                            img.draggable = false;
                            img.src = tileUrl(zoom, tileX, tileY);
                            img.onerror = () => {
                                if (selectedProjection !== 'isometric') {
                                    return;
                                }

                                if (tileRetryTimer !== null) {
                                    return;
                                }

                                tileRetryTimer = window.setTimeout(() => {
                                    tileRetryTimer = null;
                                    renderTiles();
                                }, 650);
                            };
                            img.className = 'absolute h-64 w-64 select-none';
                            activeTiles.set(key, img);
                            tileLayer.appendChild(img);
                        }

                        const tile = activeTiles.get(key);
                        tile.style.left = `${tileX * tileSize - offsetX}px`;
                        tile.style.top = `${tileY * tileSize - offsetY}px`;
                    }
                }

                for (const [key, tile] of activeTiles.entries()) {
                    if (!nextKeys.has(key)) {
                        tile.remove();
                        activeTiles.delete(key);
                    }
                }

                zoomEl.textContent = `${zoom} / ${manifest.max_zoom}`;
                tilesEl.textContent = `${info.tiles_x} x ${info.tiles_y}`;
                scheduleUrlStateSync();
            }

            function renderRegionOptions() {
                const regions = manifest?.available_regions ?? [];
                regionSelect.innerHTML = '';

                for (const region of regions) {
                    const option = document.createElement('option');
                    option.value = region;
                    option.textContent = region === 'all' ? 'All regions' : region;
                    option.selected = region === selectedRegion;
                    regionSelect.appendChild(option);
                }

                regionSelect.disabled = regions.length === 0;
            }

            function fitInitialPosition() {
                const viewportWidth = viewport.clientWidth;
                const viewportHeight = viewport.clientHeight;
                if (requestedViewState && requestedViewState.zoom >= 0 && requestedViewState.zoom <= manifest.max_zoom) {
                    zoom = requestedViewState.zoom;
                    offsetX = requestedViewState.x;
                    offsetY = requestedViewState.y;
                    requestedViewState = null;
                    clampOffsets();
                    return;
                }

                let candidate = manifest.max_zoom;
                while (candidate > 0) {
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
                const nextZoom = Math.max(0, Math.min(manifest.max_zoom, zoom + direction));

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
                renderTiles();
            }

            async function loadManifest(region = selectedRegion, preserveView = false) {
                setStatus('Loading map manifest...');
                selectedRegion = region || null;
                const hadManifest = manifest !== null;
                const query = buildQueryString(true);
                const response = await fetch(`/api/maps/manifest?${query}`);
                const payload = await response.json();

                if (!payload.available) {
                    manifest = null;
                    selectedRegion = null;
                    regionSelect.innerHTML = '<option value="">No regions</option>';
                    regionSelect.disabled = true;
                    resetActiveTiles();
                    setStatus(payload.message ?? 'Map not available.');
                    scheduleUrlStateSync();
                    return;
                }

                manifest = payload.manifest;
                selectedRegion = manifest.selected_region;
                renderRegionOptions();
                if (preserveView && hadManifest) {
                    zoom = Math.min(zoom, manifest.max_zoom);
                    clampOffsets();
                } else {
                    fitInitialPosition();
                }

                resetActiveTiles();
                renderTiles();
                setStatus(`Map loaded (${selectedProjection}, ${selectedRegion}): ${manifest.source_width} x ${manifest.source_height} blocks`);
                scheduleUrlStateSync();
            }

            async function pollBatchUntilFinished(batchId) {
                stopBatchPolling();
                activeBatchId = batchId;

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
                        await loadManifest(selectedRegion, true);
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
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ heightmap: 'WORLD_SURFACE' }),
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
                        await loadManifest(selectedRegion, true);

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
                dragStartX = event.clientX;
                dragStartY = event.clientY;
                dragOriginX = offsetX;
                dragOriginY = offsetY;
            });

            window.addEventListener('mouseup', () => {
                isDragging = false;
            });

            window.addEventListener('mousemove', event => {
                if (manifest) {
                    const rect = viewport.getBoundingClientRect();
                    const pointerX = event.clientX - rect.left;
                    const pointerY = event.clientY - rect.top;
                    const divisor = mapDivisor(zoom);
                    const worldX = Math.floor((manifest.world_min_x ?? 0) + ((offsetX + pointerX) * divisor));
                    const worldZ = Math.floor((manifest.world_min_z ?? 0) + ((offsetY + pointerY) * divisor));
                    cursorXEl.textContent = String(worldX);
                    cursorZEl.textContent = String(worldZ);
                }

                if (!isDragging || !manifest) {
                    return;
                }

                offsetX = dragOriginX - (event.clientX - dragStartX);
                offsetY = dragOriginY - (event.clientY - dragStartY);
                renderTiles();
            });

            viewport.addEventListener('wheel', event => {
                if (!manifest) {
                    return;
                }

                event.preventDefault();
                zoomAtPointer(event.deltaY < 0 ? 1 : -1, event.clientX, event.clientY);
            }, { passive: false });

            window.addEventListener('resize', () => {
                if (manifest) {
                    renderTiles();
                }
            });

            renderButton.addEventListener('click', renderWorldMap);
            regionSelect.addEventListener('change', () => {
                selectedRegion = regionSelect.value || null;
                resetActiveTiles();
                scheduleUrlStateSync();
                loadManifest(selectedRegion).catch(error => setStatus(error.message));
            });

            projectionSelect.addEventListener('change', () => {
                stopBatchPolling();
                selectedProjection = projectionSelect.value || 'birds-eye';
                selectedRegion = null;
                resetActiveTiles();
                scheduleUrlStateSync();
                loadManifest(null).catch(error => setStatus(error.message));
            });

            loadManifest().catch(error => setStatus(error.message));
        </script>
    </body>
</html>

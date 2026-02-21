<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class MinecraftIsometricTileService
{
    private const ALL_REGIONS = 'all';

    private const MANIFEST_VERSION = 3;

    private const OVERZOOM_LEVELS = 6;

    public function __construct(private Filesystem $files) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getManifest(?string $regionFile = null, bool $refresh = true, bool $includeRegions = true): ?array
    {
        $availableRegions = [];
        $selectedRegion = $regionFile ?? self::ALL_REGIONS;

        if ($includeRegions) {
            $availableRegions = $this->availableRegionsWithCoordinates();

            if ($availableRegions === []) {
                return null;
            }

            $selectedRegion = $this->normalizeRegionSelection($regionFile, $availableRegions);

            if ($selectedRegion === null) {
                return null;
            }
        }

        if ($refresh) {
            if ($selectedRegion === self::ALL_REGIONS) {
                $regionsForRefresh = $availableRegions !== [] ? $availableRegions : $this->availableRegionsWithCoordinates();

                if ($regionsForRefresh === []) {
                    return null;
                }

                $this->ensureCombinedManifestIsFresh($regionsForRefresh);
            } else {
                if (! $this->files->exists($this->sourceMapPath($selectedRegion))) {
                    return null;
                }

                $this->ensureManifestIsFresh($selectedRegion);
            }
        }

        $manifestPath = $this->manifestPath($selectedRegion);

        if (! $this->files->exists($manifestPath)) {
            return null;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['selected_region'] = $selectedRegion;
        $manifest['available_levels'] = $this->manifestLevels($manifest);
        $manifest['image_layers'] = $this->imageLayersForManifest($selectedRegion, $manifest);

        if ($includeRegions) {
            $manifest['available_regions'] = array_values(array_unique(array_merge([self::ALL_REGIONS], array_column($availableRegions, 'file'))));
        }

        return $manifest;
    }

    public function getTilePath(
        int $zoom,
        int $tileX,
        int $tileY,
        ?string $regionFile = null,
        bool $generateIfMissing = true,
        bool $refreshManifest = true
    ): ?string {
        return null;
    }

    public function rebuildRegionTiles(string $regionFile): void
    {
        $sourcePath = $this->sourceMapPath($regionFile);

        if (! $this->files->exists($sourcePath)) {
            return;
        }

        $this->files->deleteDirectory($this->tilesRootPath($regionFile));
        $this->ensureManifestIsFresh($regionFile);
    }

    public function rebuildCombinedTiles(): void
    {
        $regions = $this->availableRegionsWithCoordinates();

        if ($regions === []) {
            return;
        }

        $this->files->deleteDirectory($this->tilesRootPath(self::ALL_REGIONS));
        $this->ensureCombinedManifestIsFresh($regions);
    }

    private function ensureManifestIsFresh(string $regionFile): void
    {
        $manifestPath = $this->manifestPath($regionFile);
        $sourcePath = $this->sourceMapPath($regionFile);
        $sourceModifiedAt = $this->files->lastModified($sourcePath);

        if (! $this->files->exists($manifestPath)) {
            $this->rebuildManifest($regionFile, $sourceModifiedAt);

            return;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        if (
            ($manifest['source_modified_at'] ?? null) !== $sourceModifiedAt
            || ($manifest['manifest_version'] ?? null) !== self::MANIFEST_VERSION
        ) {
            $this->rebuildManifest($regionFile, $sourceModifiedAt);
        }
    }

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $regions
     */
    private function ensureCombinedManifestIsFresh(array $regions): void
    {
        $sourceSignature = $this->combinedSourceSignature($regions);
        $manifestPath = $this->manifestPath(self::ALL_REGIONS);

        if (! $this->files->exists($manifestPath)) {
            $this->rebuildCombinedManifest($regions, $sourceSignature);

            return;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        if (
            ($manifest['manifest_version'] ?? null) !== self::MANIFEST_VERSION
            || ($manifest['source_signature'] ?? null) !== $sourceSignature
        ) {
            $this->rebuildCombinedManifest($regions, $sourceSignature);
        }
    }

    private function rebuildManifest(string $regionFile, int $sourceModifiedAt): void
    {
        $sourcePath = $this->sourceMapPath($regionFile);
        [$width, $height] = getimagesize($sourcePath) ?: [0, 0];

        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('Unable to read isometric map dimensions from source image.');
        }

        $tileSize = $this->tileSize();
        $nativeMaxZoom = (int) ceil(log(max($width, $height) / $tileSize, 2));
        $nativeMaxZoom = max(0, $nativeMaxZoom);
        $maxZoom = $nativeMaxZoom + self::OVERZOOM_LEVELS;
        $levels = [];

        for ($zoom = 0; $zoom <= $maxZoom; $zoom++) {
            $divisor = 2 ** ($nativeMaxZoom - $zoom);
            $levelWidth = (int) ceil($width / $divisor);
            $levelHeight = (int) ceil($height / $divisor);
            $levels[(string) $zoom] = [
                'width' => $levelWidth,
                'height' => $levelHeight,
            ];
        }

        $manifest = [
            'manifest_version' => self::MANIFEST_VERSION,
            'projection' => 'isometric',
            'generated_at' => time(),
            'tile_size' => $tileSize,
            'source_width' => $width,
            'source_height' => $height,
            'world_min_x' => $this->regionXFromFile($regionFile) * 512,
            'world_min_z' => $this->regionZFromFile($regionFile) * 512,
            'native_max_zoom' => $nativeMaxZoom,
            'max_zoom' => $maxZoom,
            'source_modified_at' => $sourceModifiedAt,
            'levels' => $levels,
        ];

        $this->safeEnsureDirectoryExists($this->tilesRootPath($regionFile));
        $this->files->put(
            $this->manifestPath($regionFile),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $regions
     */
    private function rebuildCombinedManifest(array $regions, string $sourceSignature): void
    {
        $minRegionX = min(array_column($regions, 'region_x'));
        $minRegionZ = min(array_column($regions, 'region_z'));
        $pixelScale = $this->isometricPixelScale();
        $rawPlacements = [];
        $maxVerticalPadding = 0;
        $minX = null;
        $minY = null;
        $maxX = null;
        $maxY = null;
        $baseIsoHeight = ((int) ceil((512 + 512) / 2) + 8) * $pixelScale;

        foreach ($regions as $region) {
            $regionPath = $this->sourceMapPath($region['file']);
            [$regionWidth, $regionHeight] = getimagesize($regionPath) ?: [0, 0];

            if ($regionWidth <= 0 || $regionHeight <= 0) {
                continue;
            }

            $worldOffsetX = (($region['region_x'] - $minRegionX) * 512) * $pixelScale;
            $worldOffsetZ = (($region['region_z'] - $minRegionZ) * 512) * $pixelScale;
            $offsetX = $worldOffsetX - $worldOffsetZ;
            $offsetY = intdiv($worldOffsetX + $worldOffsetZ, 2);
            $verticalPadding = max(0, $regionHeight - $baseIsoHeight);
            $rawPlacements[] = [
                'file' => $region['file'],
                'raw_offset_x' => $offsetX,
                'raw_offset_y' => $offsetY,
                'region_x' => $region['region_x'],
                'region_z' => $region['region_z'],
                'width' => $regionWidth,
                'height' => $regionHeight,
                'vertical_padding' => $verticalPadding,
            ];
            $maxVerticalPadding = max($maxVerticalPadding, $verticalPadding);
        }

        if ($rawPlacements === []) {
            throw new RuntimeException('Unable to build combined isometric manifest.');
        }

        foreach ($rawPlacements as $placementIndex => $placement) {
            $normalizedOffsetY = $placement['raw_offset_y'] + ($maxVerticalPadding - $placement['vertical_padding']);
            $rawPlacements[$placementIndex]['offset_y'] = $normalizedOffsetY;

            $minX = $minX === null ? $placement['raw_offset_x'] : min($minX, $placement['raw_offset_x']);
            $minY = $minY === null ? $normalizedOffsetY : min($minY, $normalizedOffsetY);
            $maxX = $maxX === null ? ($placement['raw_offset_x'] + $placement['width']) : max($maxX, $placement['raw_offset_x'] + $placement['width']);
            $maxY = $maxY === null ? ($normalizedOffsetY + $placement['height']) : max($maxY, $normalizedOffsetY + $placement['height']);
        }

        if ($minX === null || $minY === null || $maxX === null || $maxY === null) {
            throw new RuntimeException('Unable to build combined isometric manifest bounds.');
        }

        $combinedWidth = $maxX - $minX;
        $combinedHeight = $maxY - $minY;

        if ($combinedWidth <= 0 || $combinedHeight <= 0) {
            throw new RuntimeException('Invalid combined isometric bounds.');
        }

        $placements = [];
        foreach ($rawPlacements as $placement) {
            $placements[] = [
                'file' => $placement['file'],
                'region_x' => $placement['region_x'],
                'region_z' => $placement['region_z'],
                'offset_x' => $placement['raw_offset_x'] - $minX,
                'offset_y' => $placement['offset_y'] - $minY,
                'width' => $placement['width'],
                'height' => $placement['height'],
            ];
        }

        usort($placements, static function (array $left, array $right): int {
            $yComparison = $left['offset_y'] <=> $right['offset_y'];

            if ($yComparison !== 0) {
                return $yComparison;
            }

            return $left['offset_x'] <=> $right['offset_x'];
        });

        $tileSize = $this->tileSize();
        $nativeMaxZoom = (int) ceil(log(max($combinedWidth, $combinedHeight) / $tileSize, 2));
        $nativeMaxZoom = max(0, $nativeMaxZoom);
        $maxZoom = $nativeMaxZoom + self::OVERZOOM_LEVELS;
        $levels = [];

        for ($zoom = 0; $zoom <= $maxZoom; $zoom++) {
            $divisor = 2 ** ($nativeMaxZoom - $zoom);
            $levelWidth = (int) ceil($combinedWidth / $divisor);
            $levelHeight = (int) ceil($combinedHeight / $divisor);
            $levels[(string) $zoom] = [
                'width' => $levelWidth,
                'height' => $levelHeight,
            ];
        }

        $manifest = [
            'manifest_version' => self::MANIFEST_VERSION,
            'projection' => 'isometric',
            'generated_at' => time(),
            'tile_size' => $tileSize,
            'source_width' => $combinedWidth,
            'source_height' => $combinedHeight,
            'world_min_x' => $minRegionX * 512,
            'world_min_z' => $minRegionZ * 512,
            'native_max_zoom' => $nativeMaxZoom,
            'max_zoom' => $maxZoom,
            'source_signature' => $sourceSignature,
            'levels' => $levels,
            'regions' => $regions,
            'placements' => $placements,
        ];

        $this->safeEnsureDirectoryExists($this->tilesRootPath(self::ALL_REGIONS));
        $this->files->put(
            $this->manifestPath(self::ALL_REGIONS),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    private function sourceMapPath(string $regionFile): string
    {
        return public_path('maps/isometric/regions'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }

    /**
     * @return array<int, array{file:string, region_x:int, region_z:int}>
     */
    private function availableRegionsWithCoordinates(): array
    {
        $regionImages = $this->files->glob(public_path('maps/isometric/regions'.DIRECTORY_SEPARATOR.'r.*.*.png')) ?: [];
        $regions = [];

        foreach ($regionImages as $regionImagePath) {
            $regionFile = str_replace('.png', '.mca', basename($regionImagePath));
            $regions[] = [
                'file' => $regionFile,
                'region_x' => $this->regionXFromFile($regionFile),
                'region_z' => $this->regionZFromFile($regionFile),
            ];
        }

        $regions = $this->largestConnectedRegionGroup($regions);
        usort($regions, fn (array $left, array $right): int => $left['file'] <=> $right['file']);

        return $regions;
    }

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $regions
     * @return array<int, array{file:string, region_x:int, region_z:int}>
     */
    private function largestConnectedRegionGroup(array $regions): array
    {
        if ($regions === []) {
            return [];
        }

        $indexByCoordinate = [];

        foreach ($regions as $index => $region) {
            $indexByCoordinate[$region['region_x'].':'.$region['region_z']] = $index;
        }

        $visited = [];
        $largestComponent = [];

        foreach ($regions as $startIndex => $region) {
            if (isset($visited[$startIndex])) {
                continue;
            }

            $queue = [$startIndex];
            $visited[$startIndex] = true;
            $component = [];

            while ($queue !== []) {
                $currentIndex = array_pop($queue);
                $component[] = $regions[$currentIndex];
                $current = $regions[$currentIndex];

                for ($deltaX = -1; $deltaX <= 1; $deltaX++) {
                    for ($deltaZ = -1; $deltaZ <= 1; $deltaZ++) {
                        if ($deltaX === 0 && $deltaZ === 0) {
                            continue;
                        }

                        $neighborKey = ($current['region_x'] + $deltaX).':'.($current['region_z'] + $deltaZ);

                        if (! isset($indexByCoordinate[$neighborKey])) {
                            continue;
                        }

                        $neighborIndex = $indexByCoordinate[$neighborKey];

                        if (isset($visited[$neighborIndex])) {
                            continue;
                        }

                        $visited[$neighborIndex] = true;
                        $queue[] = $neighborIndex;
                    }
                }
            }

            if (count($component) > count($largestComponent)) {
                $largestComponent = $component;
            }
        }

        return $largestComponent !== [] ? $largestComponent : $regions;
    }

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $availableRegions
     */
    private function normalizeRegionSelection(?string $regionFile, array $availableRegions): ?string
    {
        $requestedRegion = $regionFile ?? self::ALL_REGIONS;

        if ($requestedRegion === self::ALL_REGIONS || $requestedRegion === '') {
            return self::ALL_REGIONS;
        }

        $availableRegionFiles = array_column($availableRegions, 'file');

        if (! in_array($requestedRegion, $availableRegionFiles, true)) {
            return null;
        }

        return $requestedRegion;
    }

    private function manifestPath(string $regionFile): string
    {
        return $this->tilesRootPath($regionFile).DIRECTORY_SEPARATOR.'manifest.json';
    }

    private function tilesRootPath(string $regionFile): string
    {
        return public_path('maps/isometric/tiles'.DIRECTORY_SEPARATOR.$this->regionKey($regionFile));
    }

    private function regionKey(string $regionFile): string
    {
        return str_replace(['.', '/'], '_', $regionFile);
    }

    private function regionXFromFile(string $regionFile): int
    {
        preg_match('/^r\.(-?\d+)\.(-?\d+)\.mca$/', $regionFile, $matches);

        return (int) ($matches[1] ?? 0);
    }

    private function regionZFromFile(string $regionFile): int
    {
        preg_match('/^r\.(-?\d+)\.(-?\d+)\.mca$/', $regionFile, $matches);

        return (int) ($matches[2] ?? 0);
    }

    private function tileSize(): int
    {
        return 256;
    }

    private function isometricPixelScale(): int
    {
        return max(1, (int) config('render.isometric_native_pixel_scale', 1));
    }

    private function safeEnsureDirectoryExists(string $path): void
    {
        if ($this->files->isDirectory($path)) {
            return;
        }

        $this->files->makeDirectory($path, 0755, true, true);
    }

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $regions
     */
    private function combinedSourceSignature(array $regions): string
    {
        $parts = [];

        foreach ($regions as $region) {
            $parts[] = $region['file'].':'.$this->files->lastModified($this->sourceMapPath($region['file']));
        }

        return sha1(implode('|', $parts));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, int>
     */
    private function manifestLevels(array $manifest): array
    {
        $levels = array_keys((array) ($manifest['levels'] ?? []));
        $parsedLevels = array_map(static fn (string $level): int => (int) $level, $levels);
        sort($parsedLevels);

        return $parsedLevels;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, array{file:string,url:string,offset_x:int,offset_y:int,width:int,height:int}>
     */
    private function imageLayersForManifest(string $selectedRegion, array $manifest): array
    {
        $versionToken = (string) ($manifest['generated_at'] ?? time());

        if ($selectedRegion !== self::ALL_REGIONS) {
            return [[
                'file' => $selectedRegion,
                'url' => $this->imageUrlForRegion($selectedRegion, $versionToken),
                'offset_x' => 0,
                'offset_y' => 0,
                'width' => (int) ($manifest['source_width'] ?? 0),
                'height' => (int) ($manifest['source_height'] ?? 0),
            ]];
        }

        $layers = [];
        $placements = $manifest['placements'] ?? [];

        if (! is_array($placements)) {
            return [];
        }

        foreach ($placements as $placement) {
            $file = (string) ($placement['file'] ?? '');

            if ($file === '') {
                continue;
            }

            $layers[] = [
                'file' => $file,
                'url' => $this->imageUrlForRegion($file, $versionToken),
                'offset_x' => (int) ($placement['offset_x'] ?? 0),
                'offset_y' => (int) ($placement['offset_y'] ?? 0),
                'width' => (int) ($placement['width'] ?? 0),
                'height' => (int) ($placement['height'] ?? 0),
            ];
        }

        return $layers;
    }

    private function imageUrlForRegion(string $regionFile, string $versionToken): string
    {
        return '/maps/isometric/regions/'.str_replace('.mca', '.png', $regionFile).'?t='.$versionToken;
    }
}

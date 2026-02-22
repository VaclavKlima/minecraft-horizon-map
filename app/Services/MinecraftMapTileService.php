<?php

namespace App\Services;

use App\Jobs\GenerateBirdsEyeMinimapJob;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class MinecraftMapTileService
{
    private const ALL_REGIONS = 'all';

    private const MANIFEST_VERSION = 2;

    private const OVERZOOM_LEVELS = 6;

    private const EAGER_OVERZOOM_LEVELS = 0;

    private const MINIMAP_MAX_DIMENSION = 640;

    private const MINIMAP_DISPATCH_COOLDOWN_SECONDS = 45;

    public function __construct(private Filesystem $files) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getManifest(
        ?string $regionFile = null,
        bool $includeRegions = true,
        bool $refresh = true,
        bool $includeMinimap = true
    ): ?array {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to generate map tiles.');
        }

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

            if ($selectedRegion === self::ALL_REGIONS) {
                if ($refresh) {
                    $this->ensureCombinedManifestIsFresh($availableRegions);
                }
            } else {
                $sourcePath = $this->sourceMapPath($selectedRegion);

                if (! $this->files->exists($sourcePath)) {
                    return null;
                }

                if ($refresh) {
                    $this->ensureManifestIsFresh($selectedRegion);
                }
            }
        } else {
            if ($selectedRegion === self::ALL_REGIONS) {
                if ($refresh) {
                    $regionsForRefresh = $this->availableRegionsWithCoordinates();

                    if ($regionsForRefresh === []) {
                        return null;
                    }

                    $this->ensureCombinedManifestIsFresh($regionsForRefresh);
                }
            } else {
                $sourcePath = $this->sourceMapPath($selectedRegion);

                if (! $this->files->exists($sourcePath)) {
                    return null;
                }

                if ($refresh) {
                    $this->ensureManifestIsFresh($selectedRegion);
                }
            }
        }

        if (! $this->files->exists($this->manifestPath($selectedRegion))) {
            return null;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($this->manifestPath($selectedRegion)), true, flags: JSON_THROW_ON_ERROR);

        $manifest['selected_region'] = $selectedRegion;
        $manifest['available_levels'] = $this->manifestLevels($manifest);
        $manifest['image_layers'] = $this->imageLayersForManifest($selectedRegion, $manifest);

        if ($includeMinimap) {
            $manifest['minimap'] = $this->buildMinimapDescriptor($selectedRegion, $manifest, false);

            if ($manifest['minimap'] === null) {
                $this->dispatchMinimapBuild($selectedRegion);
            }
        }

        if ($includeRegions) {
            $manifest['available_regions'] = array_values(array_unique(array_merge([self::ALL_REGIONS], array_column($availableRegions, 'file'))));
        }

        return $manifest;
    }

    public function getTilePath(int $zoom, int $tileX, int $tileY, ?string $regionFile = null): ?string
    {
        $manifest = $this->getManifest($regionFile, false, false, false);

        if ($manifest === null) {
            return null;
        }

        $levelKey = (string) $zoom;

        if (! array_key_exists($levelKey, $manifest['levels'])) {
            return null;
        }

        $level = $manifest['levels'][$levelKey];

        if ($tileX < 0 || $tileY < 0 || $tileX >= $level['tiles_x'] || $tileY >= $level['tiles_y']) {
            return null;
        }

        /** @var string $selectedRegion */
        $selectedRegion = $manifest['selected_region'];
        $path = $this->tilePath($selectedRegion, $zoom, $tileX, $tileY);

        if (! $this->files->exists($path)) {
            $this->generateTile($manifest, $zoom, $tileX, $tileY, $path);
        }

        return $path;
    }

    public function rebuildRegionTiles(string $regionFile): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to generate map tiles.');
        }

        $sourcePath = $this->sourceMapPath($regionFile);

        if (! $this->files->exists($sourcePath)) {
            return;
        }

        $this->ensureManifestIsFresh($regionFile);
        $this->generateAllTilesFromManifest($regionFile, true);
    }

    public function rebuildCombinedTiles(): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to generate map tiles.');
        }

        $regions = $this->availableRegionsWithCoordinates();

        if ($regions === []) {
            return;
        }

        $this->ensureCombinedManifestIsFresh($regions);
        $this->generateAllTilesFromManifest(self::ALL_REGIONS, true);
    }

    public function rebuildMinimap(?string $regionFile = null): void
    {
        $selectedRegion = $regionFile ?? self::ALL_REGIONS;
        $manifestPath = $this->manifestPath($selectedRegion);

        if (! $this->files->exists($manifestPath)) {
            return;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->buildMinimapDescriptor($selectedRegion, $manifest, true);
        $this->clearMinimapDispatchLock($selectedRegion);
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
            $this->files->deleteDirectory($this->tilesRootPath($regionFile));
            $this->rebuildManifest($regionFile, $sourceModifiedAt);
        }
    }

    private function generateAllTilesFromManifest(string $regionFile, bool $eagerOnly = false): void
    {
        $manifestPath = $this->manifestPath($regionFile);

        if (! $this->files->exists($manifestPath)) {
            return;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['selected_region'] = $regionFile;
        $maxEagerZoom = $eagerOnly ? $this->eagerMaxZoomForManifest($manifest) : null;

        foreach ($manifest['levels'] as $zoom => $level) {
            $zoomLevel = (int) $zoom;

            if ($maxEagerZoom !== null && $zoomLevel > $maxEagerZoom) {
                continue;
            }

            for ($tileY = 0; $tileY < $level['tiles_y']; $tileY++) {
                for ($tileX = 0; $tileX < $level['tiles_x']; $tileX++) {
                    $path = $this->tilePath($regionFile, $zoomLevel, $tileX, $tileY);

                    if ($this->files->exists($path)) {
                        continue;
                    }

                    $this->generateTile($manifest, $zoomLevel, $tileX, $tileY, $path);
                }
            }
        }
    }

    private function rebuildManifest(string $regionFile, int $sourceModifiedAt): void
    {
        $sourcePath = $this->sourceMapPath($regionFile);
        [$width, $height] = getimagesize($sourcePath) ?: [0, 0];

        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('Unable to read map dimensions from source image.');
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
                'tiles_x' => (int) ceil($levelWidth / $tileSize),
                'tiles_y' => (int) ceil($levelHeight / $tileSize),
            ];
        }

        $this->safeEnsureDirectoryExists($this->tilesRootPath($regionFile));

        $manifest = [
            'manifest_version' => self::MANIFEST_VERSION,
            'projection' => 'birds-eye',
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

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->files->put($this->manifestPath($regionFile), $json);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function generateTile(array $manifest, int $zoom, int $tileX, int $tileY, string $path): void
    {
        /** @var string $selectedRegion */
        $selectedRegion = $manifest['selected_region'];
        $tileSize = $this->tileSize();
        $tileImage = imagecreatetruecolor($tileSize, $tileSize);

        if ($tileImage === false) {
            throw new RuntimeException('Unable to allocate tile image.');
        }

        imagealphablending($tileImage, false);
        imagesavealpha($tileImage, true);
        $transparent = imagecolorallocatealpha($tileImage, 0, 0, 0, 127);
        imagefill($tileImage, 0, 0, $transparent);

        $sourceWidth = $manifest['source_width'];
        $sourceHeight = $manifest['source_height'];
        $nativeMaxZoom = $manifest['native_max_zoom'] ?? $manifest['max_zoom'];
        $divisor = (float) (2 ** ($nativeMaxZoom - $zoom));
        $sourceTileSize = $tileSize * $divisor;
        $sourceX = $tileX * $sourceTileSize;
        $sourceY = $tileY * $sourceTileSize;
        $sourceCopyWidth = min($sourceTileSize, max(0, $sourceWidth - $sourceX));
        $sourceCopyHeight = min($sourceTileSize, max(0, $sourceHeight - $sourceY));

        if ($sourceCopyWidth <= 0 || $sourceCopyHeight <= 0) {
            imagedestroy($tileImage);
            throw new RuntimeException('Invalid tile coordinates.');
        }

        if ($selectedRegion === self::ALL_REGIONS) {
            $this->renderCombinedTileToImage($tileImage, $manifest, $sourceX, $sourceY, $sourceCopyWidth, $sourceCopyHeight, $divisor);
        } else {
            $targetWidth = (int) ceil($sourceCopyWidth / $divisor);
            $targetHeight = (int) ceil($sourceCopyHeight / $divisor);
            $this->renderSingleRegionTileToImage($tileImage, $selectedRegion, $sourceX, $sourceY, $sourceCopyWidth, $sourceCopyHeight, $targetWidth, $targetHeight);
        }

        $this->safeEnsureDirectoryExists(dirname($path));
        imagepng($tileImage, $path);
        imagedestroy($tileImage);
    }

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $regions
     */
    private function ensureCombinedManifestIsFresh(array $regions): void
    {
        $signature = $this->combinedSourceSignature($regions);
        $manifestPath = $this->manifestPath(self::ALL_REGIONS);

        if (! $this->files->exists($manifestPath)) {
            $this->rebuildCombinedManifest($regions, $signature);

            return;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        if (
            ($manifest['source_signature'] ?? null) !== $signature
            || ($manifest['manifest_version'] ?? null) !== self::MANIFEST_VERSION
        ) {
            $this->files->deleteDirectory($this->tilesRootPath(self::ALL_REGIONS));
            $this->rebuildCombinedManifest($regions, $signature);
        }
    }

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $regions
     */
    private function rebuildCombinedManifest(array $regions, string $sourceSignature): void
    {
        $minRegionX = min(array_column($regions, 'region_x'));
        $maxRegionX = max(array_column($regions, 'region_x'));
        $minRegionZ = min(array_column($regions, 'region_z'));
        $maxRegionZ = max(array_column($regions, 'region_z'));
        $width = (($maxRegionX - $minRegionX) + 1) * 512;
        $height = (($maxRegionZ - $minRegionZ) + 1) * 512;
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
                'tiles_x' => (int) ceil($levelWidth / $tileSize),
                'tiles_y' => (int) ceil($levelHeight / $tileSize),
            ];
        }

        $manifest = [
            'manifest_version' => self::MANIFEST_VERSION,
            'projection' => 'birds-eye',
            'generated_at' => time(),
            'tile_size' => $tileSize,
            'source_width' => $width,
            'source_height' => $height,
            'world_min_x' => $minRegionX * 512,
            'world_min_z' => $minRegionZ * 512,
            'native_max_zoom' => $nativeMaxZoom,
            'max_zoom' => $maxZoom,
            'source_signature' => $sourceSignature,
            'levels' => $levels,
            'regions' => $regions,
        ];

        $this->safeEnsureDirectoryExists($this->tilesRootPath(self::ALL_REGIONS));
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->files->put($this->manifestPath(self::ALL_REGIONS), $json);
    }

    private function renderSingleRegionTileToImage(
        \GdImage $tileImage,
        string $regionFile,
        int $sourceX,
        int $sourceY,
        int $sourceCopyWidth,
        int $sourceCopyHeight,
        int $targetWidth,
        int $targetHeight
    ): void {
        $sourcePath = $this->sourceMapPath($regionFile);
        $sourceImage = imagecreatefrompng($sourcePath);

        if ($sourceImage === false) {
            throw new RuntimeException('Unable to open source map image.');
        }

        imagecopyresampled(
            $tileImage,
            $sourceImage,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $sourceCopyWidth,
            $sourceCopyHeight
        );

        imagedestroy($sourceImage);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function renderCombinedTileToImage(
        \GdImage $tileImage,
        array $manifest,
        int $sourceX,
        int $sourceY,
        int $sourceCopyWidth,
        int $sourceCopyHeight,
        float $divisor
    ): void {
        $tileRectX2 = $sourceX + $sourceCopyWidth;
        $tileRectY2 = $sourceY + $sourceCopyHeight;
        $minRegionX = intdiv((int) $manifest['world_min_x'], 512);
        $minRegionZ = intdiv((int) $manifest['world_min_z'], 512);

        foreach ($manifest['regions'] as $region) {
            $regionPixelX = ($region['region_x'] - $minRegionX) * 512;
            $regionPixelY = ($region['region_z'] - $minRegionZ) * 512;
            $regionX2 = $regionPixelX + 512;
            $regionY2 = $regionPixelY + 512;
            $overlapX1 = max($sourceX, $regionPixelX);
            $overlapY1 = max($sourceY, $regionPixelY);
            $overlapX2 = min($tileRectX2, $regionX2);
            $overlapY2 = min($tileRectY2, $regionY2);

            if ($overlapX1 >= $overlapX2 || $overlapY1 >= $overlapY2) {
                continue;
            }

            $sourceImage = imagecreatefrompng($this->sourceMapPath($region['file']));

            if ($sourceImage === false) {
                continue;
            }

            $srcX = $overlapX1 - $regionPixelX;
            $srcY = $overlapY1 - $regionPixelY;
            $srcWidth = $overlapX2 - $overlapX1;
            $srcHeight = $overlapY2 - $overlapY1;
            $destX = (int) floor(($overlapX1 - $sourceX) / $divisor);
            $destY = (int) floor(($overlapY1 - $sourceY) / $divisor);
            $destWidth = max(1, (int) ceil($srcWidth / $divisor));
            $destHeight = max(1, (int) ceil($srcHeight / $divisor));
            imagecopyresampled(
                $tileImage,
                $sourceImage,
                $destX,
                $destY,
                $srcX,
                $srcY,
                $destWidth,
                $destHeight,
                $srcWidth,
                $srcHeight
            );
            imagedestroy($sourceImage);
        }
    }

    private function combinedSourceSignature(array $regions): string
    {
        $parts = [];

        foreach ($regions as $region) {
            $parts[] = $region['file'].':'.$this->files->lastModified($this->sourceMapPath($region['file']));
        }

        return sha1(implode('|', $parts));
    }

    /**
     * @return array<int, array{file:string, region_x:int, region_z:int}>
     */
    private function availableRegionsWithCoordinates(): array
    {
        $regionImages = $this->files->glob(public_path('maps/regions'.DIRECTORY_SEPARATOR.'r.*.*.png')) ?: [];
        $regions = [];

        foreach ($regionImages as $regionImagePath) {
            $regionFile = str_replace('.png', '.mca', basename($regionImagePath));
            $regions[] = [
                'file' => $regionFile,
                'region_x' => $this->regionXFromFile($regionFile),
                'region_z' => $this->regionZFromFile($regionFile),
            ];
        }

        usort($regions, fn (array $left, array $right): int => $left['file'] <=> $right['file']);

        return $regions;
    }

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $availableRegions
     */
    private function normalizeRegionSelection(?string $regionFile, array $availableRegions): ?string
    {
        $requestedRegion = $regionFile ?? self::ALL_REGIONS;

        if ($requestedRegion === self::ALL_REGIONS) {
            return self::ALL_REGIONS;
        }

        $availableRegionFiles = array_column($availableRegions, 'file');

        if (! in_array($requestedRegion, $availableRegionFiles, true)) {
            return null;
        }

        return $requestedRegion;
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

    private function sourceMapPath(string $regionFile): string
    {
        return public_path('maps/regions'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
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

        $regions = $manifest['regions'] ?? [];
        if (! is_array($regions)) {
            return [];
        }

        $worldMinX = (int) ($manifest['world_min_x'] ?? 0);
        $worldMinZ = (int) ($manifest['world_min_z'] ?? 0);
        $layers = [];

        foreach ($regions as $region) {
            if (! is_array($region)) {
                continue;
            }

            $file = (string) ($region['file'] ?? '');
            if ($file === '') {
                continue;
            }

            $regionX = (int) ($region['region_x'] ?? 0);
            $regionZ = (int) ($region['region_z'] ?? 0);
            $offsetX = ($regionX * 512) - $worldMinX;
            $offsetY = ($regionZ * 512) - $worldMinZ;
            $layers[] = [
                'file' => $file,
                'url' => $this->imageUrlForRegion($file, $versionToken),
                'offset_x' => $offsetX,
                'offset_y' => $offsetY,
                'width' => 512,
                'height' => 512,
            ];
        }

        usort($layers, static function (array $left, array $right): int {
            $yComparison = $left['offset_y'] <=> $right['offset_y'];

            if ($yComparison !== 0) {
                return $yComparison;
            }

            return $left['offset_x'] <=> $right['offset_x'];
        });

        return $layers;
    }

    private function imageUrlForRegion(string $regionFile, string $versionToken): string
    {
        return '/maps/regions/'.str_replace('.mca', '.png', $regionFile).'?t='.$versionToken;
    }

    private function manifestPath(string $regionFile): string
    {
        return $this->tilesRootPath($regionFile).DIRECTORY_SEPARATOR.'manifest.json';
    }

    private function tilesRootPath(string $regionFile): string
    {
        return public_path('maps/tiles'.DIRECTORY_SEPARATOR.$this->regionKey($regionFile));
    }

    private function tilePath(string $regionFile, int $zoom, int $tileX, int $tileY): string
    {
        return $this->tilesRootPath($regionFile)
            .DIRECTORY_SEPARATOR.$zoom
            .DIRECTORY_SEPARATOR.$tileX
            .DIRECTORY_SEPARATOR.$tileY.'.png';
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, int>
     */
    private function availableTileLevels(string $regionFile, array $manifest): array
    {
        $levels = [];

        foreach ($manifest['levels'] as $zoom => $level) {
            $zoomLevel = (int) $zoom;
            $levelPath = $this->tilesRootPath($regionFile).DIRECTORY_SEPARATOR.$zoomLevel;

            if ($this->files->isDirectory($levelPath)) {
                $levels[] = $zoomLevel;
            }
        }

        if ($levels !== []) {
            sort($levels);

            return $levels;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{
     *   url:string,
     *   width:int,
     *   height:int,
     *   source_width:int,
     *   source_height:int
     * }|null
     */
    private function buildMinimapDescriptor(string $selectedRegion, array $manifest, bool $allowBuild): ?array
    {
        if ($selectedRegion === self::ALL_REGIONS) {
            return $this->buildCombinedMinimapDescriptor($manifest, $allowBuild);
        }

        return $this->buildRegionMinimapDescriptor($selectedRegion, $allowBuild);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{
     *   url:string,
     *   width:int,
     *   height:int,
     *   source_width:int,
     *   source_height:int
     * }|null
     */
    private function buildCombinedMinimapDescriptor(array $manifest, bool $allowBuild): ?array
    {
        $sourceWidth = (int) ($manifest['source_width'] ?? 0);
        $sourceHeight = (int) ($manifest['source_height'] ?? 0);
        $sourceSignature = (string) ($manifest['source_signature'] ?? '');
        $generatedAt = (int) ($manifest['generated_at'] ?? 0);
        $regions = $manifest['regions'] ?? [];

        if ($sourceWidth <= 0 || $sourceHeight <= 0 || $sourceSignature === '' || ! is_array($regions)) {
            return null;
        }

        $relativeImagePath = 'maps/minimap/all.png';
        $imagePath = public_path($relativeImagePath);
        $metaPath = public_path('maps/minimap/all.meta.json');
        $signature = sha1($sourceSignature.'|'.$sourceWidth.'|'.$sourceHeight);

        if (! $this->isMinimapFresh($imagePath, $metaPath, $signature)) {
            if (! $allowBuild) {
                if (! $this->files->exists($imagePath) || ! $this->files->exists($metaPath)) {
                    return null;
                }
            } else {
                $this->safeEnsureDirectoryExists(dirname($imagePath));
                $built = $this->buildCombinedMinimapImage($imagePath, $manifest, $sourceWidth, $sourceHeight);

                if ($built === null) {
                    return null;
                }

                $this->files->put(
                    $metaPath,
                    json_encode([
                        'signature' => $signature,
                        'width' => $built['width'],
                        'height' => $built['height'],
                    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                );
            }
        }

        /** @var array<string, mixed> $meta */
        $meta = json_decode($this->files->get($metaPath), true, flags: JSON_THROW_ON_ERROR);
        $minimapWidth = (int) ($meta['width'] ?? 0);
        $minimapHeight = (int) ($meta['height'] ?? 0);

        if ($minimapWidth <= 0 || $minimapHeight <= 0) {
            return null;
        }

        return [
            'url' => '/'.$relativeImagePath.'?t='.$generatedAt,
            'width' => $minimapWidth,
            'height' => $minimapHeight,
            'source_width' => $sourceWidth,
            'source_height' => $sourceHeight,
        ];
    }

    /**
     * @return array{
     *   url:string,
     *   width:int,
     *   height:int,
     *   source_width:int,
     *   source_height:int
     * }|null
     */
    private function buildRegionMinimapDescriptor(string $regionFile, bool $allowBuild): ?array
    {
        $sourcePath = $this->sourceMapPath($regionFile);

        if (! $this->files->exists($sourcePath)) {
            return null;
        }

        [$sourceWidth, $sourceHeight] = getimagesize($sourcePath) ?: [0, 0];
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return null;
        }

        $regionKey = $this->regionKey($regionFile);
        $relativeImagePath = 'maps/minimap/'.$regionKey.'.png';
        $imagePath = public_path($relativeImagePath);
        $metaPath = public_path('maps/minimap/'.$regionKey.'.meta.json');
        $signature = sha1((string) $this->files->lastModified($sourcePath).'|'.(string) $this->files->size($sourcePath));

        if (! $this->isMinimapFresh($imagePath, $metaPath, $signature)) {
            if (! $allowBuild) {
                if (! $this->files->exists($imagePath) || ! $this->files->exists($metaPath)) {
                    return null;
                }
            } else {
                $this->safeEnsureDirectoryExists(dirname($imagePath));
                $built = $this->buildSingleImageMinimap($sourcePath, $imagePath, $sourceWidth, $sourceHeight);

                if ($built === null) {
                    return null;
                }

                $this->files->put(
                    $metaPath,
                    json_encode([
                        'signature' => $signature,
                        'width' => $built['width'],
                        'height' => $built['height'],
                    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                );
            }
        }

        /** @var array<string, mixed> $meta */
        $meta = json_decode($this->files->get($metaPath), true, flags: JSON_THROW_ON_ERROR);
        $minimapWidth = (int) ($meta['width'] ?? 0);
        $minimapHeight = (int) ($meta['height'] ?? 0);

        if ($minimapWidth <= 0 || $minimapHeight <= 0) {
            return null;
        }

        return [
            'url' => '/'.$relativeImagePath.'?t='.$this->files->lastModified($sourcePath),
            'width' => $minimapWidth,
            'height' => $minimapHeight,
            'source_width' => (int) $sourceWidth,
            'source_height' => (int) $sourceHeight,
        ];
    }

    private function isMinimapFresh(string $imagePath, string $metaPath, string $signature): bool
    {
        if (! $this->files->exists($imagePath) || ! $this->files->exists($metaPath)) {
            return false;
        }

        try {
            /** @var array<string, mixed> $meta */
            $meta = json_decode($this->files->get($metaPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        return (string) ($meta['signature'] ?? '') === $signature;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{width:int,height:int}|null
     */
    private function buildCombinedMinimapImage(
        string $outputPath,
        array $manifest,
        int $sourceWidth,
        int $sourceHeight
    ): ?array {
        $regions = $manifest['regions'] ?? [];

        if (! is_array($regions)) {
            return null;
        }

        $scale = min(
            1.0,
            self::MINIMAP_MAX_DIMENSION / max(1, max($sourceWidth, $sourceHeight))
        );
        $targetWidth = max(1, (int) floor($sourceWidth * $scale));
        $targetHeight = max(1, (int) floor($sourceHeight * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            return null;
        }

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        $backgroundColor = imagecolorallocate($canvas, 15, 23, 42);
        imagefill($canvas, 0, 0, $backgroundColor);

        $minRegionX = intdiv((int) ($manifest['world_min_x'] ?? 0), 512);
        $minRegionZ = intdiv((int) ($manifest['world_min_z'] ?? 0), 512);

        foreach ($regions as $region) {
            if (! is_array($region)) {
                continue;
            }

            $file = (string) ($region['file'] ?? '');
            if ($file === '') {
                continue;
            }

            $regionImagePath = $this->sourceMapPath($file);
            if (! $this->files->exists($regionImagePath)) {
                continue;
            }

            $sourceImage = @imagecreatefrompng($regionImagePath);
            if ($sourceImage === false) {
                continue;
            }

            $regionPixelX = (((int) ($region['region_x'] ?? 0)) - $minRegionX) * 512;
            $regionPixelY = (((int) ($region['region_z'] ?? 0)) - $minRegionZ) * 512;
            $dstX = (int) floor($regionPixelX * $scale);
            $dstY = (int) floor($regionPixelY * $scale);
            $dstWidth = max(1, (int) floor(512 * $scale));
            $dstHeight = max(1, (int) floor(512 * $scale));

            imagecopyresampled(
                $canvas,
                $sourceImage,
                $dstX,
                $dstY,
                0,
                0,
                $dstWidth,
                $dstHeight,
                max(1, imagesx($sourceImage)),
                max(1, imagesy($sourceImage))
            );

            imagedestroy($sourceImage);
        }

        imagepng($canvas, $outputPath);
        imagedestroy($canvas);

        return [
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    /**
     * @return array{width:int,height:int}|null
     */
    private function buildSingleImageMinimap(
        string $sourcePath,
        string $outputPath,
        int $sourceWidth,
        int $sourceHeight
    ): ?array {
        $sourceImage = @imagecreatefrompng($sourcePath);
        if ($sourceImage === false) {
            return null;
        }

        $scale = min(
            1.0,
            self::MINIMAP_MAX_DIMENSION / max(1, max($sourceWidth, $sourceHeight))
        );
        $targetWidth = max(1, (int) floor($sourceWidth * $scale));
        $targetHeight = max(1, (int) floor($sourceHeight * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            imagedestroy($sourceImage);

            return null;
        }

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        imagecopyresampled(
            $canvas,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            max(1, imagesx($sourceImage)),
            max(1, imagesy($sourceImage))
        );
        imagepng($canvas, $outputPath);
        imagedestroy($canvas);
        imagedestroy($sourceImage);

        return [
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    private function dispatchMinimapBuild(string $selectedRegion): void
    {
        $lockPath = $this->minimapDispatchLockPath($selectedRegion);
        $now = time();
        $lastDispatchedAt = $this->files->exists($lockPath)
            ? (int) trim($this->files->get($lockPath))
            : 0;

        if (($now - $lastDispatchedAt) < self::MINIMAP_DISPATCH_COOLDOWN_SECONDS) {
            return;
        }

        $this->safeEnsureDirectoryExists(dirname($lockPath));
        $this->files->put($lockPath, (string) $now);
        GenerateBirdsEyeMinimapJob::dispatch($selectedRegion === self::ALL_REGIONS ? null : $selectedRegion);
    }

    private function clearMinimapDispatchLock(string $selectedRegion): void
    {
        $lockPath = $this->minimapDispatchLockPath($selectedRegion);

        if ($this->files->exists($lockPath)) {
            $this->files->delete($lockPath);
        }
    }

    private function minimapDispatchLockPath(string $selectedRegion): string
    {
        return storage_path(
            'app'.DIRECTORY_SEPARATOR.'birds-eye-minimap'.DIRECTORY_SEPARATOR.$this->regionKey($selectedRegion).'.dispatch.lock'
        );
    }

    private function safeEnsureDirectoryExists(string $path): void
    {
        if ($this->files->isDirectory($path)) {
            return;
        }

        $this->files->makeDirectory($path, 0755, true, true);
    }

    private function regionKey(string $regionFile): string
    {
        return str_replace(['.', '/'], '_', $regionFile);
    }

    private function tileSize(): int
    {
        return 256;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function eagerMaxZoomForManifest(array $manifest): int
    {
        $nativeMaxZoom = (int) ($manifest['native_max_zoom'] ?? $manifest['max_zoom'] ?? 0);
        $maxZoom = (int) ($manifest['max_zoom'] ?? $nativeMaxZoom);

        return min($maxZoom, $nativeMaxZoom + self::EAGER_OVERZOOM_LEVELS);
    }
}

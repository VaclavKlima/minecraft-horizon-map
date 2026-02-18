<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class MinecraftMapTileService
{
    private const ALL_REGIONS = 'all';

    private const MANIFEST_VERSION = 2;

    private const OVERZOOM_LEVELS = 6;

    private const EAGER_OVERZOOM_LEVELS = 0;

    public function __construct(private Filesystem $files) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getManifest(?string $regionFile = null): ?array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to generate map tiles.');
        }

        $availableRegions = $this->availableRegionsWithCoordinates();

        if ($availableRegions === []) {
            return null;
        }

        $selectedRegion = $this->normalizeRegionSelection($regionFile, $availableRegions);

        if ($selectedRegion === null) {
            return null;
        }

        if ($selectedRegion === self::ALL_REGIONS) {
            $this->ensureCombinedManifestIsFresh($availableRegions);
        } else {
            $sourcePath = $this->sourceMapPath($selectedRegion);

            if (! $this->files->exists($sourcePath)) {
                return null;
            }

            $this->ensureManifestIsFresh($selectedRegion);
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($this->manifestPath($selectedRegion)), true, flags: JSON_THROW_ON_ERROR);

        $manifest['selected_region'] = $selectedRegion;
        $manifest['available_regions'] = array_values(array_unique(array_merge([self::ALL_REGIONS], array_column($availableRegions, 'file'))));

        return $manifest;
    }

    public function getTilePath(int $zoom, int $tileX, int $tileY, ?string $regionFile = null): ?string
    {
        $manifest = $this->getManifest($regionFile);

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

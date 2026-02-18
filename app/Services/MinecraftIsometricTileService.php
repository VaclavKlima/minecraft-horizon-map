<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class MinecraftIsometricTileService
{
    private const ALL_REGIONS = 'all';

    private const MANIFEST_VERSION = 1;

    private const OVERZOOM_LEVELS = 6;

    private const EAGER_OVERZOOM_LEVELS = 0;

    public function __construct(private Filesystem $files) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getManifest(?string $regionFile = null, bool $refresh = true): ?array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to generate isometric map tiles.');
        }

        $availableRegions = $this->availableRegionsWithCoordinates();

        if ($availableRegions === []) {
            return null;
        }

        $selectedRegion = $this->normalizeRegionSelection($regionFile, $availableRegions);

        if ($selectedRegion === null) {
            return null;
        }

        if ($refresh) {
            if ($selectedRegion === self::ALL_REGIONS) {
                $this->ensureCombinedManifestIsFresh($availableRegions);
            } else {
                $this->ensureManifestIsFresh($selectedRegion);
            }
        } elseif (! $this->files->exists($this->manifestPath($selectedRegion))) {
            return null;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($this->manifestPath($selectedRegion)), true, flags: JSON_THROW_ON_ERROR);
        $manifest['selected_region'] = $selectedRegion;
        $manifest['available_regions'] = array_values(array_unique(array_merge([self::ALL_REGIONS], array_column($availableRegions, 'file'))));

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
        $manifest = $this->getManifest($regionFile, $refreshManifest);

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

        if (! $this->files->exists($path) && $generateIfMissing) {
            $this->generateTile($manifest, $selectedRegion, $zoom, $tileX, $tileY, $path);
        }

        return $this->files->exists($path) ? $path : null;
    }

    public function rebuildRegionTiles(string $regionFile): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to generate isometric map tiles.');
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
            throw new RuntimeException('The GD extension is required to generate isometric map tiles.');
        }

        $regions = $this->availableRegionsWithCoordinates();

        if ($regions === []) {
            return;
        }

        $sourceSignature = $this->combinedSourceSignature($regions);
        $this->files->deleteDirectory($this->tilesRootPath(self::ALL_REGIONS));
        $this->rebuildCombinedSourceAndManifest($regions, $sourceSignature);
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

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $regions
     */
    private function ensureCombinedManifestIsFresh(array $regions): void
    {
        $sourceSignature = $this->combinedSourceSignature($regions);
        $manifestPath = $this->manifestPath(self::ALL_REGIONS);
        $sourcePath = $this->sourceMapPath(self::ALL_REGIONS);

        if (! $this->files->exists($manifestPath) || ! $this->files->exists($sourcePath)) {
            $this->rebuildCombinedSourceAndManifest($regions, $sourceSignature);

            return;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        if (
            ($manifest['manifest_version'] ?? null) !== self::MANIFEST_VERSION
            || ($manifest['source_signature'] ?? null) !== $sourceSignature
        ) {
            $this->files->deleteDirectory($this->tilesRootPath(self::ALL_REGIONS));
            $this->rebuildCombinedSourceAndManifest($regions, $sourceSignature);
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
                'tiles_x' => (int) ceil($levelWidth / $tileSize),
                'tiles_y' => (int) ceil($levelHeight / $tileSize),
            ];
        }

        $this->safeEnsureDirectoryExists($this->tilesRootPath($regionFile));
        $manifest = [
            'manifest_version' => self::MANIFEST_VERSION,
            'projection' => 'isometric',
            'generated_at' => time(),
            'tile_size' => $tileSize,
            'source_width' => $width,
            'source_height' => $height,
            'world_min_x' => $regionFile === self::ALL_REGIONS ? 0 : $this->regionXFromFile($regionFile) * 512,
            'world_min_z' => $regionFile === self::ALL_REGIONS ? 0 : $this->regionZFromFile($regionFile) * 512,
            'native_max_zoom' => $nativeMaxZoom,
            'max_zoom' => $maxZoom,
            'source_modified_at' => $sourceModifiedAt,
            'levels' => $levels,
        ];

        $this->files->put(
            $this->manifestPath($regionFile),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param  array<int, array{file:string, region_x:int, region_z:int}>  $regions
     */
    private function rebuildCombinedSourceAndManifest(array $regions, string $sourceSignature): void
    {
        $minRegionX = min(array_column($regions, 'region_x'));
        $minRegionZ = min(array_column($regions, 'region_z'));
        $placements = [];
        $minX = null;
        $minY = null;
        $maxX = null;
        $maxY = null;

        foreach ($regions as $region) {
            $regionPath = $this->sourceMapPath($region['file']);
            [$regionWidth, $regionHeight] = getimagesize($regionPath) ?: [0, 0];

            if ($regionWidth <= 0 || $regionHeight <= 0) {
                continue;
            }

            $worldOffsetX = ($region['region_x'] - $minRegionX) * 512;
            $worldOffsetZ = ($region['region_z'] - $minRegionZ) * 512;
            $offsetX = $worldOffsetX - $worldOffsetZ;
            $offsetY = intdiv($worldOffsetX + $worldOffsetZ, 2);
            $placements[] = [
                'file' => $region['file'],
                'offset_x' => $offsetX,
                'offset_y' => $offsetY,
                'width' => $regionWidth,
                'height' => $regionHeight,
            ];

            $minX = $minX === null ? $offsetX : min($minX, $offsetX);
            $minY = $minY === null ? $offsetY : min($minY, $offsetY);
            $maxX = $maxX === null ? ($offsetX + $regionWidth) : max($maxX, $offsetX + $regionWidth);
            $maxY = $maxY === null ? ($offsetY + $regionHeight) : max($maxY, $offsetY + $regionHeight);
        }

        if ($placements === [] || $minX === null || $minY === null || $maxX === null || $maxY === null) {
            throw new RuntimeException('Unable to build combined isometric source image.');
        }

        $combinedWidth = $maxX - $minX;
        $combinedHeight = $maxY - $minY;
        $combinedImage = imagecreatetruecolor($combinedWidth, $combinedHeight);

        if ($combinedImage === false) {
            throw new RuntimeException('Unable to allocate combined isometric source image.');
        }

        imagealphablending($combinedImage, false);
        imagesavealpha($combinedImage, true);
        $transparent = imagecolorallocatealpha($combinedImage, 0, 0, 0, 127);
        imagefill($combinedImage, 0, 0, $transparent);
        imagealphablending($combinedImage, true);

        usort($placements, function (array $left, array $right): int {
            $yComparison = $left['offset_y'] <=> $right['offset_y'];

            if ($yComparison !== 0) {
                return $yComparison;
            }

            return $left['offset_x'] <=> $right['offset_x'];
        });

        foreach ($placements as $placement) {
            $regionImage = imagecreatefrompng($this->sourceMapPath($placement['file']));

            if ($regionImage === false) {
                continue;
            }

            imagecopy(
                $combinedImage,
                $regionImage,
                $placement['offset_x'] - $minX,
                $placement['offset_y'] - $minY,
                0,
                0,
                $placement['width'],
                $placement['height']
            );
            imagedestroy($regionImage);
        }

        $combinedPath = $this->sourceMapPath(self::ALL_REGIONS);
        $this->safeEnsureDirectoryExists(dirname($combinedPath));
        imagepng($combinedImage, $combinedPath);
        imagedestroy($combinedImage);

        $sourceModifiedAt = $this->files->lastModified($combinedPath);
        $this->rebuildManifest(self::ALL_REGIONS, $sourceModifiedAt);
        $manifestPath = $this->manifestPath(self::ALL_REGIONS);
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['source_signature'] = $sourceSignature;
        $manifest['world_min_x'] = $minRegionX * 512;
        $manifest['world_min_z'] = $minRegionZ * 512;
        $manifest['regions'] = $regions;
        $this->files->put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function generateAllTilesFromManifest(string $regionFile, bool $eagerOnly = false): void
    {
        $manifestPath = $this->manifestPath($regionFile);

        if (! $this->files->exists($manifestPath)) {
            return;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $maxEagerZoom = $eagerOnly ? $this->eagerMaxZoomForManifest($manifest) : null;
        $sourceImage = imagecreatefrompng($this->sourceMapPath($regionFile));

        if ($sourceImage === false) {
            throw new RuntimeException('Unable to open source isometric map image.');
        }

        foreach ($manifest['levels'] as $zoom => $level) {
            $zoomLevel = (int) $zoom;

            if ($maxEagerZoom !== null && $zoomLevel > $maxEagerZoom) {
                continue;
            }

            $levelImage = $this->buildLevelImage($manifest, $sourceImage, $zoomLevel, $level);

            if ($levelImage === false) {
                continue;
            }

            for ($tileY = 0; $tileY < $level['tiles_y']; $tileY++) {
                for ($tileX = 0; $tileX < $level['tiles_x']; $tileX++) {
                    $path = $this->tilePath($regionFile, $zoomLevel, $tileX, $tileY);

                    if ($this->files->exists($path)) {
                        continue;
                    }

                    $this->generateTileFromLevelImage($levelImage, $level, $tileX, $tileY, $path);
                }
            }

            if ($levelImage !== $sourceImage) {
                imagedestroy($levelImage);
            }
        }

        imagedestroy($sourceImage);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $level
     */
    private function buildLevelImage(array $manifest, \GdImage $sourceImage, int $zoom, array $level): \GdImage|false
    {
        $nativeMaxZoom = $manifest['native_max_zoom'] ?? $manifest['max_zoom'];

        if ($zoom === $nativeMaxZoom) {
            return $sourceImage;
        }

        $levelWidth = max(1, (int) $level['width']);
        $levelHeight = max(1, (int) $level['height']);
        $sourceWidth = (int) $manifest['source_width'];
        $sourceHeight = (int) $manifest['source_height'];
        $levelImage = imagecreatetruecolor($levelWidth, $levelHeight);

        if ($levelImage === false) {
            return false;
        }

        imagealphablending($levelImage, false);
        imagesavealpha($levelImage, true);
        $transparent = imagecolorallocatealpha($levelImage, 0, 0, 0, 127);
        imagefill($levelImage, 0, 0, $transparent);
        imagecopyresampled(
            $levelImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $levelWidth,
            $levelHeight,
            $sourceWidth,
            $sourceHeight
        );

        return $levelImage;
    }

    /**
     * @param  array<string, mixed>  $level
     */
    private function generateTileFromLevelImage(\GdImage $levelImage, array $level, int $tileX, int $tileY, string $path): void
    {
        $tileSize = $this->tileSize();
        $tileImage = imagecreatetruecolor($tileSize, $tileSize);

        if ($tileImage === false) {
            throw new RuntimeException('Unable to allocate isometric tile image.');
        }

        imagealphablending($tileImage, false);
        imagesavealpha($tileImage, true);
        $transparent = imagecolorallocatealpha($tileImage, 0, 0, 0, 127);
        imagefill($tileImage, 0, 0, $transparent);

        $sourceX = $tileX * $tileSize;
        $sourceY = $tileY * $tileSize;
        $copyWidth = min($tileSize, max(0, ((int) $level['width']) - $sourceX));
        $copyHeight = min($tileSize, max(0, ((int) $level['height']) - $sourceY));

        if ($copyWidth > 0 && $copyHeight > 0) {
            imagecopy($tileImage, $levelImage, 0, 0, $sourceX, $sourceY, $copyWidth, $copyHeight);
        }

        $this->safeEnsureDirectoryExists(dirname($path));
        imagepng($tileImage, $path);
        imagedestroy($tileImage);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function generateTile(
        array $manifest,
        string $regionFile,
        int $zoom,
        int $tileX,
        int $tileY,
        string $path,
        ?\GdImage $sourceImage = null
    ): void {
        $tileSize = $this->tileSize();
        $tileImage = imagecreatetruecolor($tileSize, $tileSize);

        if ($tileImage === false) {
            throw new RuntimeException('Unable to allocate isometric tile image.');
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
        $sourceX = (int) floor($tileX * $sourceTileSize);
        $sourceY = (int) floor($tileY * $sourceTileSize);
        $sourceX2 = (int) min($sourceWidth, ceil(($tileX + 1) * $sourceTileSize));
        $sourceY2 = (int) min($sourceHeight, ceil(($tileY + 1) * $sourceTileSize));
        $sourceCopyWidth = $sourceX2 - $sourceX;
        $sourceCopyHeight = $sourceY2 - $sourceY;

        if ($sourceCopyWidth <= 0 || $sourceCopyHeight <= 0) {
            imagedestroy($tileImage);
            throw new RuntimeException('Invalid isometric tile coordinates.');
        }

        $sourceImageHandle = $sourceImage;

        if ($sourceImageHandle === null) {
            $sourceImageHandle = imagecreatefrompng($this->sourceMapPath($regionFile));
        }

        if ($sourceImageHandle === false) {
            imagedestroy($tileImage);
            throw new RuntimeException('Unable to open source isometric map image.');
        }

        $targetWidth = (int) ceil($sourceCopyWidth / $divisor);
        $targetHeight = (int) ceil($sourceCopyHeight / $divisor);
        imagecopyresampled(
            $tileImage,
            $sourceImageHandle,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $sourceCopyWidth,
            $sourceCopyHeight
        );

        if ($sourceImage === null) {
            imagedestroy($sourceImageHandle);
        }

        $this->safeEnsureDirectoryExists(dirname($path));
        imagepng($tileImage, $path);
        imagedestroy($tileImage);
    }

    private function sourceMapPath(string $regionFile): string
    {
        if ($regionFile === self::ALL_REGIONS) {
            return public_path('maps/isometric/regions'.DIRECTORY_SEPARATOR.'all.png');
        }

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

        usort($regions, fn (array $left, array $right): int => $left['file'] <=> $right['file']);

        return $regions;
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

    private function tilePath(string $regionFile, int $zoom, int $tileX, int $tileY): string
    {
        return $this->tilesRootPath($regionFile)
            .DIRECTORY_SEPARATOR.$zoom
            .DIRECTORY_SEPARATOR.$tileX
            .DIRECTORY_SEPARATOR.$tileY.'.png';
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
     */
    private function eagerMaxZoomForManifest(array $manifest): int
    {
        $nativeMaxZoom = (int) ($manifest['native_max_zoom'] ?? $manifest['max_zoom'] ?? 0);
        $maxZoom = (int) ($manifest['max_zoom'] ?? $nativeMaxZoom);

        return min($maxZoom, $nativeMaxZoom + self::EAGER_OVERZOOM_LEVELS);
    }
}

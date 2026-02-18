<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class MinecraftIsometricRenderer
{
    private const RENDER_METADATA_VERSION = 9;

    private const DEPTH_SCALE = 0.6;

    private const HEIGHT_BASELINE = -128;

    private const HEIGHT_CEILING = 384;

    private const VOXEL_LAYERS = 24;

    private const VOXEL_BYTES_PER_LAYER = 3;

    public function __construct(private Filesystem $files) {}

    /**
     * @return array{
     *     region_file:string,
     *     file:string,
     *     relative_path:string,
     *     width_blocks:int,
     *     height_blocks:int
     * }|null
     */
    public function renderRegion(string $regionFile): ?array
    {
        $sourcePath = $this->sourceMapPath($regionFile);

        if (! $this->files->exists($sourcePath)) {
            return null;
        }

        $sourceImage = imagecreatefrompng($sourcePath);

        if ($sourceImage === false) {
            throw new RuntimeException('Unable to open source birds-eye map image for isometric rendering.');
        }

        $heightMapPath = $this->heightMapPath($regionFile);
        $heightImage = $this->files->exists($heightMapPath) ? imagecreatefrompng($heightMapPath) : false;
        $surfaceMapPath = $this->surfaceMapPath($regionFile);
        $surfaceImage = $this->files->exists($surfaceMapPath) ? imagecreatefrompng($surfaceMapPath) : false;
        $voxelMapPath = $this->voxelMapPath($regionFile);
        $voxelData = $this->files->exists($voxelMapPath) ? $this->files->get($voxelMapPath) : null;

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        $heightSpan = self::HEIGHT_CEILING - self::HEIGHT_BASELINE;
        $verticalDepthPadding = (int) ceil($heightSpan * self::DEPTH_SCALE);
        $isoWidth = $sourceWidth + $sourceHeight;
        $isoHeight = (int) ceil(($sourceWidth + $sourceHeight) / 2) + $verticalDepthPadding + 2;
        $isometricImage = imagecreatetruecolor($isoWidth, $isoHeight);

        if ($isometricImage === false) {
            imagedestroy($sourceImage);
            throw new RuntimeException('Unable to allocate isometric output image.');
        }

        imagealphablending($isometricImage, false);
        imagesavealpha($isometricImage, true);
        $transparent = imagecolorallocatealpha($isometricImage, 0, 0, 0, 127);
        imagefill($isometricImage, 0, 0, $transparent);

        $colorCache = [];
        $wallColorCache = [];
        $depthOffsets = $this->buildDepthOffsetMap($heightImage, $sourceWidth, $sourceHeight);
        $surfaceMasks = $this->buildSurfaceMasks($surfaceImage, $sourceWidth, $sourceHeight);
        $hasVoxelData = $this->hasUsableVoxelData($voxelData, $sourceWidth, $sourceHeight);
        $columnBytes = self::VOXEL_LAYERS * self::VOXEL_BYTES_PER_LAYER;

        for ($sourceY = 0; $sourceY < $sourceHeight; $sourceY++) {
            for ($sourceX = 0; $sourceX < $sourceWidth; $sourceX++) {
                $sourceColor = imagecolorat($sourceImage, $sourceX, $sourceY);
                $r = ($sourceColor >> 16) & 0xFF;
                $g = ($sourceColor >> 8) & 0xFF;
                $b = $sourceColor & 0xFF;
                $a = ($sourceColor & 0x7F000000) >> 24;
                $cacheKey = ($a << 24) | ($r << 16) | ($g << 8) | $b;

                if (! array_key_exists($cacheKey, $colorCache)) {
                    $colorCache[$cacheKey] = imagecolorallocatealpha($isometricImage, $r, $g, $b, $a);
                }

                $depthOffset = $this->depthOffsetAt($depthOffsets, $sourceWidth, $sourceX, $sourceY);
                $isoX = ($sourceX - $sourceY) + ($sourceHeight - 1);
                $isoY = (int) floor(($sourceX + $sourceY) / 2) + $verticalDepthPadding - $depthOffset;
                imagesetpixel($isometricImage, $isoX, $isoY, $colorCache[$cacheKey]);

                $currentMask = $this->surfaceMaskAt($surfaceMasks, $sourceWidth, $sourceX, $sourceY);

                if ($currentMask === 0) {
                    continue;
                }

                $eastMask = 0;

                if ($sourceX < ($sourceWidth - 1)) {
                    $eastMask = $this->surfaceMaskAt($surfaceMasks, $sourceWidth, $sourceX + 1, $sourceY);
                }

                $southMask = 0;

                if ($sourceY < ($sourceHeight - 1)) {
                    $southMask = $this->surfaceMaskAt($surfaceMasks, $sourceWidth, $sourceX, $sourceY + 1);
                }

                $eastVisibleMask = $currentMask & (~$eastMask & 0xFFFFFF);
                $southVisibleMask = $currentMask & (~$southMask & 0xFFFFFF);
                $visibleMask = $eastVisibleMask | $southVisibleMask;

                if ($visibleMask === 0) {
                    continue;
                }

                if ($hasVoxelData && $voxelData !== null) {
                    $pixelIndex = ($sourceY * $sourceWidth) + $sourceX;
                    $baseOffset = $pixelIndex * $columnBytes;

                    for ($depthLayer = 0; $depthLayer < self::VOXEL_LAYERS; $depthLayer++) {
                        $layerBit = 1 << $depthLayer;

                        if (($visibleMask & $layerBit) === 0) {
                            continue;
                        }

                        $layerOffset = $baseOffset + ($depthLayer * self::VOXEL_BYTES_PER_LAYER);
                        $cr = ord($voxelData[$layerOffset]);
                        $cg = ord($voxelData[$layerOffset + 1]);
                        $cb = ord($voxelData[$layerOffset + 2]);

                        if ($cr === 0 && $cg === 0 && $cb === 0) {
                            continue;
                        }

                        $wallY = $isoY + max(1, (int) round(($depthLayer + 1) * self::DEPTH_SCALE));

                        if ($wallY >= $isoHeight) {
                            continue;
                        }

                        if (($eastVisibleMask & $layerBit) !== 0) {
                            $wr = (int) round($cr * 0.8);
                            $wg = (int) round($cg * 0.8);
                            $wb = (int) round($cb * 0.8);
                            $wallCacheKey = ($a << 24) | ($wr << 16) | ($wg << 8) | $wb;

                            if (! array_key_exists($wallCacheKey, $wallColorCache)) {
                                $wallColorCache[$wallCacheKey] = imagecolorallocatealpha($isometricImage, $wr, $wg, $wb, $a);
                            }

                            $eastFaceX = $isoX + 1;

                            if ($eastFaceX >= 0 && $eastFaceX < $isoWidth) {
                                imagesetpixel($isometricImage, $eastFaceX, $wallY, $wallColorCache[$wallCacheKey]);
                            }
                        }

                        if (($southVisibleMask & $layerBit) !== 0) {
                            $wr = (int) round($cr * 0.72);
                            $wg = (int) round($cg * 0.72);
                            $wb = (int) round($cb * 0.72);
                            $wallCacheKey = ($a << 24) | ($wr << 16) | ($wg << 8) | $wb;

                            if (! array_key_exists($wallCacheKey, $wallColorCache)) {
                                $wallColorCache[$wallCacheKey] = imagecolorallocatealpha($isometricImage, $wr, $wg, $wb, $a);
                            }

                            $southFaceX = $isoX - 1;

                            if ($southFaceX >= 0 && $southFaceX < $isoWidth) {
                                imagesetpixel($isometricImage, $southFaceX, $wallY, $wallColorCache[$wallCacheKey]);
                            }
                        }
                    }
                } else {
                    for ($depthLayer = 0; $depthLayer < self::VOXEL_LAYERS; $depthLayer++) {
                        $layerBit = 1 << $depthLayer;

                        if (($visibleMask & $layerBit) === 0) {
                            continue;
                        }

                        $eastOpen = ($eastVisibleMask & $layerBit) !== 0;
                        $southOpen = ($southVisibleMask & $layerBit) !== 0;

                        if (! $eastOpen && ! $southOpen) {
                            continue;
                        }

                        $wallY = $isoY + max(1, (int) round(($depthLayer + 1) * self::DEPTH_SCALE));

                        if ($wallY >= $isoHeight) {
                            continue;
                        }

                        $shadeFactor = $eastOpen && $southOpen ? 0.72 : 0.78;
                        $wr = (int) round($r * $shadeFactor);
                        $wg = (int) round($g * $shadeFactor);
                        $wb = (int) round($b * $shadeFactor);
                        $wallCacheKey = ($a << 24) | ($wr << 16) | ($wg << 8) | $wb;

                        if (! array_key_exists($wallCacheKey, $wallColorCache)) {
                            $wallColorCache[$wallCacheKey] = imagecolorallocatealpha($isometricImage, $wr, $wg, $wb, $a);
                        }

                        imagesetpixel($isometricImage, $isoX, $wallY, $wallColorCache[$wallCacheKey]);
                    }
                }
            }
        }

        $outputRelativePath = 'maps/isometric/regions/'.str_replace('.mca', '.png', $regionFile);
        $outputPath = public_path($outputRelativePath);
        $this->files->ensureDirectoryExists(dirname($outputPath));
        imagepng($isometricImage, $outputPath);
        imagedestroy($isometricImage);
        imagedestroy($sourceImage);

        if ($heightImage !== false) {
            imagedestroy($heightImage);
        }

        if ($surfaceImage !== false) {
            imagedestroy($surfaceImage);
        }

        $metadata = [
            'version' => self::RENDER_METADATA_VERSION,
            'source_modified_at' => $this->files->lastModified($sourcePath),
            'rendered_at' => time(),
        ];
        $metadataPath = $this->renderMetadataPath($regionFile);
        $this->files->ensureDirectoryExists(dirname($metadataPath));
        $this->files->put($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return [
            'region_file' => $regionFile,
            'file' => basename($outputPath),
            'relative_path' => $outputRelativePath,
            'width_blocks' => $isoWidth,
            'height_blocks' => $isoHeight,
        ];
    }

    public function regionNeedsRendering(string $regionFile): bool
    {
        $sourcePath = $this->sourceMapPath($regionFile);
        $renderPath = $this->isometricRegionPath($regionFile);
        $metadataPath = $this->renderMetadataPath($regionFile);
        $heightMapPath = $this->heightMapPath($regionFile);
        $surfaceMapPath = $this->surfaceMapPath($regionFile);
        $voxelMapPath = $this->voxelMapPath($regionFile);

        if (
            ! $this->files->exists($sourcePath)
            || ! $this->files->exists($renderPath)
            || ! $this->files->exists($metadataPath)
            || ! $this->files->exists($heightMapPath)
            || ! $this->files->exists($surfaceMapPath)
            || ! $this->files->exists($voxelMapPath)
        ) {
            return true;
        }

        try {
            /** @var array<string, mixed> $metadata */
            $metadata = json_decode($this->files->get($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return true;
        }

        return ($metadata['version'] ?? null) !== self::RENDER_METADATA_VERSION
            || ($metadata['source_modified_at'] ?? null) !== $this->files->lastModified($sourcePath);
    }

    private function sourceMapPath(string $regionFile): string
    {
        return public_path('maps/regions'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }

    private function isometricRegionPath(string $regionFile): string
    {
        return public_path('maps/isometric/regions'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }

    private function renderMetadataPath(string $regionFile): string
    {
        return public_path('maps/isometric/regions'.DIRECTORY_SEPARATOR.'.meta'.DIRECTORY_SEPARATOR.str_replace('.mca', '.json', $regionFile));
    }

    private function heightMapPath(string $regionFile): string
    {
        return public_path('maps/regions'.DIRECTORY_SEPARATOR.'.heights'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }

    private function surfaceMapPath(string $regionFile): string
    {
        return public_path('maps/regions'.DIRECTORY_SEPARATOR.'.surface'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }

    private function voxelMapPath(string $regionFile): string
    {
        return public_path('maps/regions'.DIRECTORY_SEPARATOR.'.voxels'.DIRECTORY_SEPARATOR.str_replace('.mca', '.bin', $regionFile));
    }

    private function decodeHeightAt(\GdImage $heightImage, int $x, int $y): int
    {
        $encodedColor = imagecolorat($heightImage, $x, $y);
        $red = ($encodedColor >> 16) & 0xFF;
        $green = ($encodedColor >> 8) & 0xFF;
        $encodedHeight = ($red << 8) | $green;

        return $encodedHeight - 32768;
    }

    /**
     * @return array<int, int>
     */
    private function buildDepthOffsetMap(\GdImage|false $heightImage, int $width, int $height): array
    {
        $depthOffsets = array_fill(0, $width * $height, 0);

        if ($heightImage === false) {
            return $depthOffsets;
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $surfaceHeight = $this->decodeHeightAt($heightImage, $x, $y);
                $clampedHeight = max(self::HEIGHT_BASELINE, min(self::HEIGHT_CEILING, $surfaceHeight));
                $depthOffsets[($y * $width) + $x] = (int) round(($clampedHeight - self::HEIGHT_BASELINE) * self::DEPTH_SCALE);
            }
        }

        return $depthOffsets;
    }

    /**
     * @return array<int, int>
     */
    private function buildSurfaceMasks(\GdImage|false $surfaceImage, int $width, int $height): array
    {
        $masks = array_fill(0, $width * $height, 0);

        if ($surfaceImage === false) {
            return $masks;
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($surfaceImage, $x, $y);
                $red = ($color >> 16) & 0xFF;
                $green = ($color >> 8) & 0xFF;
                $blue = $color & 0xFF;
                $masks[($y * $width) + $x] = ($red << 16) | ($green << 8) | $blue;
            }
        }

        return $masks;
    }

    /**
     * @param  array<int, int>  $depthOffsets
     */
    private function depthOffsetAt(array $depthOffsets, int $width, int $x, int $y): int
    {
        return $depthOffsets[($y * $width) + $x] ?? 0;
    }

    /**
     * @param  array<int, int>  $surfaceMasks
     */
    private function surfaceMaskAt(array $surfaceMasks, int $width, int $x, int $y): int
    {
        return $surfaceMasks[($y * $width) + $x] ?? 0;
    }

    private function hasUsableVoxelData(?string $voxelData, int $width, int $height): bool
    {
        if ($voxelData === null) {
            return false;
        }

        $expectedBytes = $width * $height * self::VOXEL_LAYERS * self::VOXEL_BYTES_PER_LAYER;

        return strlen($voxelData) === $expectedBytes;
    }
}

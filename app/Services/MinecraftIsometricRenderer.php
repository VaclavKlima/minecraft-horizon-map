<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class MinecraftIsometricRenderer
{
    private const RENDER_METADATA_VERSION = 14;

    private const DEPTH_SCALE = 0.6;

    private const HEIGHT_BASELINE = -128;

    private const HEIGHT_CEILING = 384;

    public function __construct(
        private Filesystem $files,
        private MinecraftBirdsEyeRenderer $minecraftBirdsEyeRenderer
    ) {}

    /**
     * @return array{
     *     region_file:string,
     *     file:string,
     *     relative_path:string,
     *     width_blocks:int,
     *     height_blocks:int
     * }|null
     */
    public function renderRegion(string $regionFile, string $heightmapType = 'WORLD_SURFACE'): ?array
    {
        $snapshot = $this->minecraftBirdsEyeRenderer->buildRegionSectionSnapshot($regionFile);

        if ($snapshot === null) {
            return null;
        }

        $sourceWidth = (int) $snapshot['width_blocks'];
        $sourceHeight = (int) $snapshot['height_blocks'];
        $sectionsByChunk = [];
        $minSectionY = null;
        $maxSectionY = null;

        foreach ($snapshot['chunks'] as $chunk) {
            $chunkKey = $this->chunkKey((int) $chunk['chunk_x'], (int) $chunk['chunk_z']);
            $sectionsByChunk[$chunkKey] = $chunk['sections'];
            $sectionYs = array_keys($chunk['sections']);

            if ($sectionYs === []) {
                continue;
            }

            $chunkMinSectionY = (int) min($sectionYs);
            $chunkMaxSectionY = (int) max($sectionYs);
            $minSectionY = $minSectionY === null ? $chunkMinSectionY : min($minSectionY, $chunkMinSectionY);
            $maxSectionY = $maxSectionY === null ? $chunkMaxSectionY : max($maxSectionY, $chunkMaxSectionY);
        }

        if ($sectionsByChunk === [] || $minSectionY === null || $maxSectionY === null) {
            return null;
        }

        $minY = $minSectionY * 16;
        $maxY = ($maxSectionY * 16) + 15;
        $heightSpan = max(1, $maxY - $minY + 1);
        $verticalDepthPadding = (int) ceil($heightSpan * self::DEPTH_SCALE);
        $isoWidth = $sourceWidth + $sourceHeight;
        $isoHeight = (int) ceil(($sourceWidth + $sourceHeight) / 2) + $verticalDepthPadding + 8;
        $isometricImage = imagecreatetruecolor($isoWidth, $isoHeight);

        if ($isometricImage === false) {
            throw new RuntimeException('Unable to allocate isometric output image.');
        }

        imagealphablending($isometricImage, false);
        imagesavealpha($isometricImage, true);
        $transparent = imagecolorallocatealpha($isometricImage, 0, 0, 0, 127);
        imagefill($isometricImage, 0, 0, $transparent);

        $colorCache = [];
        $depthBuffer = array_fill(0, $isoWidth * $isoHeight, PHP_INT_MIN);

        foreach ($snapshot['chunks'] as $chunk) {
            $chunkX = (int) $chunk['chunk_x'];
            $chunkZ = (int) $chunk['chunk_z'];
            /** @var array<int, array<string, mixed>> $chunkSections */
            $chunkSections = $chunk['sections'];

            foreach ($chunkSections as $sectionY => $section) {
                for ($localY = 0; $localY < 16; $localY++) {
                    for ($localZ = 0; $localZ < 16; $localZ++) {
                        for ($localX = 0; $localX < 16; $localX++) {
                            $paletteIndex = $this->paletteIndexAt($section, $localX, $localZ, $localY);

                            if (($section['palette_is_air'][$paletteIndex] ?? true) === true) {
                                continue;
                            }

                            $worldX = ($chunkX * 16) + $localX;
                            $worldY = ((int) $sectionY * 16) + $localY;
                            $worldZ = ($chunkZ * 16) + $localZ;

                            if ($worldX < 0 || $worldX >= $sourceWidth || $worldZ < 0 || $worldZ >= $sourceHeight) {
                                continue;
                            }

                            $topExposed = ! $this->isSolidAt($sectionsByChunk, $worldX, $worldY + 1, $worldZ, $minY, $maxY);
                            $eastExposed = ! $this->isSolidAt($sectionsByChunk, $worldX + 1, $worldY, $worldZ, $minY, $maxY);
                            $southExposed = ! $this->isSolidAt($sectionsByChunk, $worldX, $worldY, $worldZ + 1, $minY, $maxY);

                            if (! $topExposed && ! $eastExposed && ! $southExposed) {
                                continue;
                            }

                            [$red, $green, $blue] = $section['palette_colors'][$paletteIndex] ?? [90, 90, 92];
                            $topColor = $this->colorHandle($isometricImage, $colorCache, $red, $green, $blue, 0);
                            $isoX = ($worldX - $worldZ) + ($sourceHeight - 1);
                            $depthOffset = $this->depthOffsetForHeight($worldY + 1);
                            $isoY = (int) floor(($worldX + $worldZ) / 2) + $verticalDepthPadding - $depthOffset;
                            $baseDepth = (($worldX + $worldZ) * 8192) + ($worldY * 4);

                            if ($topExposed) {
                                $this->plotPixelIfCloser($isometricImage, $depthBuffer, $isoWidth, $isoX, $isoY, $topColor, $baseDepth + 3);
                            }

                            $sideStep = max(1, (int) round(self::DEPTH_SCALE));

                            if ($eastExposed) {
                                $eastColor = $this->colorHandle(
                                    $isometricImage,
                                    $colorCache,
                                    (int) round($red * 0.80),
                                    (int) round($green * 0.80),
                                    (int) round($blue * 0.80),
                                    0
                                );

                                for ($step = 1; $step <= $sideStep; $step++) {
                                    $this->plotPixelIfCloser(
                                        $isometricImage,
                                        $depthBuffer,
                                        $isoWidth,
                                        $isoX + 1,
                                        $isoY + $step,
                                        $eastColor,
                                        $baseDepth + 2
                                    );
                                }
                            }

                            if ($southExposed) {
                                $southColor = $this->colorHandle(
                                    $isometricImage,
                                    $colorCache,
                                    (int) round($red * 0.72),
                                    (int) round($green * 0.72),
                                    (int) round($blue * 0.72),
                                    0
                                );

                                for ($step = 1; $step <= $sideStep; $step++) {
                                    $this->plotPixelIfCloser(
                                        $isometricImage,
                                        $depthBuffer,
                                        $isoWidth,
                                        $isoX - 1,
                                        $isoY + $step,
                                        $southColor,
                                        $baseDepth + 1
                                    );
                                }
                            }

                            $this->renderPhysicalShadow(
                                $isometricImage,
                                $depthBuffer,
                                $colorCache,
                                $sectionsByChunk,
                                $isoWidth,
                                $sourceHeight,
                                $verticalDepthPadding,
                                $worldX,
                                $worldY,
                                $worldZ,
                                $minY,
                                $maxY
                            );
                        }
                    }
                }
            }
        }

        $outputRelativePath = 'maps/isometric/regions/'.str_replace('.mca', '.png', $regionFile);
        $outputPath = public_path($outputRelativePath);
        $this->files->ensureDirectoryExists(dirname($outputPath));
        imagepng($isometricImage, $outputPath);
        imagedestroy($isometricImage);

        $metadata = [
            'version' => self::RENDER_METADATA_VERSION,
            'heightmap_type' => $heightmapType,
            'source_modified_at' => $this->files->lastModified($this->regionPath($regionFile)),
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

    public function regionNeedsRendering(string $regionFile, string $heightmapType = 'WORLD_SURFACE'): bool
    {
        $sourcePath = $this->regionPath($regionFile);
        $renderPath = $this->isometricRegionPath($regionFile);
        $metadataPath = $this->renderMetadataPath($regionFile);

        if (! $this->files->exists($sourcePath) || ! $this->files->exists($renderPath) || ! $this->files->exists($metadataPath)) {
            return true;
        }

        try {
            /** @var array<string, mixed> $metadata */
            $metadata = json_decode($this->files->get($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return true;
        }

        return ($metadata['version'] ?? null) !== self::RENDER_METADATA_VERSION
            || ($metadata['heightmap_type'] ?? null) !== $heightmapType
            || ($metadata['source_modified_at'] ?? null) !== $this->files->lastModified($sourcePath);
    }

    private function regionPath(string $regionFile): string
    {
        return public_path('region'.DIRECTORY_SEPARATOR.$regionFile);
    }

    private function isometricRegionPath(string $regionFile): string
    {
        return public_path('maps/isometric/regions'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }

    private function renderMetadataPath(string $regionFile): string
    {
        return public_path('maps/isometric/regions'.DIRECTORY_SEPARATOR.'.meta'.DIRECTORY_SEPARATOR.str_replace('.mca', '.json', $regionFile));
    }

    private function chunkKey(int $chunkX, int $chunkZ): string
    {
        return $chunkX.':'.$chunkZ;
    }

    private function depthOffsetForHeight(int $height): int
    {
        $clampedHeight = max(self::HEIGHT_BASELINE, min(self::HEIGHT_CEILING, $height));

        return (int) round(($clampedHeight - self::HEIGHT_BASELINE) * self::DEPTH_SCALE);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sectionsByChunk
     */
    private function isSolidAt(array $sectionsByChunk, int $worldX, int $worldY, int $worldZ, int $minY, int $maxY): bool
    {
        if ($worldY < $minY || $worldY > $maxY || $worldX < 0 || $worldX >= 512 || $worldZ < 0 || $worldZ >= 512) {
            return false;
        }

        $section = $this->sectionAt($sectionsByChunk, $worldX, $worldY, $worldZ);

        if ($section === null) {
            return false;
        }

        $localX = $worldX % 16;
        $localZ = $worldZ % 16;
        $sectionY = $this->floorDivide($worldY, 16);
        $localY = $worldY - ($sectionY * 16);
        $paletteIndex = $this->paletteIndexAt($section, $localX, $localZ, $localY);

        return ($section['palette_is_air'][$paletteIndex] ?? true) === false;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sectionsByChunk
     * @return array{0:int,1:int,2:int}|null
     */
    private function colorAt(array $sectionsByChunk, int $worldX, int $worldY, int $worldZ): ?array
    {
        $section = $this->sectionAt($sectionsByChunk, $worldX, $worldY, $worldZ);

        if ($section === null) {
            return null;
        }

        $localX = $worldX % 16;
        $localZ = $worldZ % 16;
        $sectionY = $this->floorDivide($worldY, 16);
        $localY = $worldY - ($sectionY * 16);
        $paletteIndex = $this->paletteIndexAt($section, $localX, $localZ, $localY);

        if (($section['palette_is_air'][$paletteIndex] ?? true) === true) {
            return null;
        }

        return $section['palette_colors'][$paletteIndex] ?? [90, 90, 92];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sectionsByChunk
     * @return array<string, mixed>|null
     */
    private function sectionAt(array $sectionsByChunk, int $worldX, int $worldY, int $worldZ): ?array
    {
        $chunkX = intdiv($worldX, 16);
        $chunkZ = intdiv($worldZ, 16);
        $sectionY = $this->floorDivide($worldY, 16);
        $chunkKey = $this->chunkKey($chunkX, $chunkZ);

        return $sectionsByChunk[$chunkKey][$sectionY] ?? null;
    }

    private function floorDivide(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        if (($value % $divisor) !== 0 && $value < 0) {
            return $quotient - 1;
        }

        return $quotient;
    }

    /**
     * @param  array{
     *     block_data_words: array<int, array{hi:int,lo:int}>,
     *     bits_per_entry:?int,
     *     values_per_long:?int,
     *     uses_padded_layout:bool
     * }  $section
     */
    private function paletteIndexAt(array $section, int $localX, int $localZ, int $yInSection): int
    {
        $blockIndex = ($yInSection * 256) + ($localZ * 16) + $localX;

        if ($section['bits_per_entry'] === null || $section['block_data_words'] === []) {
            return 0;
        }

        return $this->readPackedIndexForBlock($section, $blockIndex);
    }

    /**
     * @param  array{
     *     block_data_words: array<int, array{hi:int,lo:int}>,
     *     bits_per_entry:?int,
     *     values_per_long:?int,
     *     uses_padded_layout:bool
     * }  $section
     */
    private function readPackedIndexForBlock(array $section, int $blockIndex): int
    {
        $bitsPerEntry = (int) $section['bits_per_entry'];
        $valuesPerLong = (int) ($section['values_per_long'] ?? 0);

        if ($section['uses_padded_layout'] && $valuesPerLong > 0) {
            $longIndex = intdiv($blockIndex, $valuesPerLong);
            $indexInLong = $blockIndex % $valuesPerLong;
            $bitOffset = $indexInLong * $bitsPerEntry;

            return $this->readPackedBits($section['block_data_words'], $longIndex, $bitOffset, $bitsPerEntry);
        }

        $startBit = $blockIndex * $bitsPerEntry;
        $longIndex = intdiv($startBit, 64);
        $bitOffset = $startBit % 64;

        return $this->readPackedBits($section['block_data_words'], $longIndex, $bitOffset, $bitsPerEntry);
    }

    /**
     * @param  array<int, array{hi:int,lo:int}>  $longWords
     */
    private function readPackedBits(array $longWords, int $longIndex, int $bitOffset, int $bitCount): int
    {
        if (! isset($longWords[$longIndex])) {
            return 0;
        }

        if ($bitOffset + $bitCount <= 64) {
            return $this->extractBitsFromWord($longWords[$longIndex], $bitOffset, $bitCount);
        }

        $firstPartBitCount = 64 - $bitOffset;
        $firstPart = $this->extractBitsFromWord($longWords[$longIndex], $bitOffset, $firstPartBitCount);
        $remainingBitCount = $bitCount - $firstPartBitCount;
        $secondPart = 0;

        if (isset($longWords[$longIndex + 1])) {
            $secondPart = $this->extractBitsFromWord($longWords[$longIndex + 1], 0, $remainingBitCount);
        }

        return $firstPart | ($secondPart << $firstPartBitCount);
    }

    /**
     * @param  array{hi:int,lo:int}  $word
     */
    private function extractBitsFromWord(array $word, int $bitOffset, int $bitCount): int
    {
        if ($bitCount <= 0) {
            return 0;
        }

        $mask = (1 << $bitCount) - 1;

        if ($bitOffset < 32) {
            if (($bitOffset + $bitCount) <= 32) {
                return ($word['lo'] >> $bitOffset) & $mask;
            }

            $lowBits = 32 - $bitOffset;
            $lowMask = (1 << $lowBits) - 1;
            $lowPart = ($word['lo'] >> $bitOffset) & $lowMask;
            $highBits = $bitCount - $lowBits;
            $highMask = (1 << $highBits) - 1;
            $highPart = $word['hi'] & $highMask;

            return $lowPart | ($highPart << $lowBits);
        }

        $highOffset = $bitOffset - 32;

        return ($word['hi'] >> $highOffset) & $mask;
    }

    /**
     * @param  array<int, int>  $depthBuffer
     */
    private function plotPixelIfCloser(
        \GdImage $image,
        array &$depthBuffer,
        int $imageWidth,
        int $x,
        int $y,
        int $color,
        int $depth
    ): void {
        if ($x < 0 || $y < 0) {
            return;
        }

        $imageHeight = imagesy($image);

        if ($x >= $imageWidth || $y >= $imageHeight) {
            return;
        }

        $bufferIndex = ($y * $imageWidth) + $x;

        if (($depthBuffer[$bufferIndex] ?? PHP_INT_MIN) > $depth) {
            return;
        }

        $depthBuffer[$bufferIndex] = $depth;
        imagesetpixel($image, $x, $y, $color);
    }

    /**
     * @param  array<int, int>  $cache
     */
    private function colorHandle(\GdImage $image, array &$cache, int $r, int $g, int $b, int $a): int
    {
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));
        $a = max(0, min(127, $a));
        $key = ($a << 24) | ($r << 16) | ($g << 8) | $b;

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = imagecolorallocatealpha($image, $r, $g, $b, $a);
        }

        return $cache[$key];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sectionsByChunk
     * @param  array<int, int>  $depthBuffer
     * @param  array<int, int>  $colorCache
     */
    private function renderPhysicalShadow(
        \GdImage $image,
        array &$depthBuffer,
        array &$colorCache,
        array $sectionsByChunk,
        int $isoWidth,
        int $sourceHeight,
        int $verticalDepthPadding,
        int $worldX,
        int $worldY,
        int $worldZ,
        int $minY,
        int $maxY
    ): void {
        $airDepth = 0;
        $targetY = null;

        for ($scanY = $worldY - 1; $scanY >= $minY; $scanY--) {
            if ($this->isSolidAt($sectionsByChunk, $worldX, $scanY, $worldZ, $minY, $maxY)) {
                if ($airDepth >= 2) {
                    $targetY = $scanY;
                }

                break;
            }

            $airDepth++;
        }

        if ($targetY === null) {
            return;
        }

        $underlayColor = $this->colorAt($sectionsByChunk, $worldX, $targetY, $worldZ);

        if ($underlayColor === null) {
            return;
        }

        [$ur, $ug, $ub] = $underlayColor;
        $isoX = ($worldX - $worldZ) + ($sourceHeight - 1);
        $underlayDepthOffset = $this->depthOffsetForHeight($targetY + 1);
        $underlayIsoY = (int) floor(($worldX + $worldZ) / 2) + $verticalDepthPadding - $underlayDepthOffset;
        $depth = (($worldX + $worldZ) * 8192) + ($targetY * 4) + 4;
        $shadowColor = $this->colorHandle(
            $image,
            $colorCache,
            (int) round($ur * 0.45),
            (int) round($ug * 0.45),
            (int) round($ub * 0.45),
            80
        );

        $this->plotPixelIfCloser($image, $depthBuffer, $isoWidth, $isoX, $underlayIsoY, $shadowColor, $depth);
    }
}

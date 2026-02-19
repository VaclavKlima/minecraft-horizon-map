<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class MinecraftIsometricRenderer
{
    private const RENDER_METADATA_VERSION = 16;

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
        $sourceWidth = 32 * 16;
        $sourceHeight = 32 * 16;
        [$columns, $minY, $maxY, $chunkCount] = $this->buildSolidColumns($regionFile, $sourceWidth, $sourceHeight);

        if ($chunkCount === 0 || $columns === [] || $minY === null || $maxY === null) {
            return null;
        }

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

        foreach ($columns as $columnIndex => $column) {
            $worldX = $columnIndex % $sourceWidth;
            $worldZ = intdiv($columnIndex, $sourceWidth);
            $eastColumn = ($worldX + 1 < $sourceWidth) ? ($columns[$columnIndex + 1] ?? null) : null;
            $southColumn = ($worldZ + 1 < $sourceHeight) ? ($columns[$columnIndex + $sourceWidth] ?? null) : null;
            $columnYs = $column['ys'];
            $columnColors = $column['colors'];
            $eastYs = $eastColumn['ys'] ?? [];
            $southYs = $southColumn['ys'] ?? [];

            foreach ($columnYs as $index => $worldY) {
                $topExposed = ! $this->containsY($columnYs, $worldY + 1);
                $eastExposed = ! $this->containsY($eastYs, $worldY);
                $southExposed = ! $this->containsY($southYs, $worldY);

                if (! $topExposed && ! $eastExposed && ! $southExposed) {
                    continue;
                }

                $packedColor = $columnColors[$index] ?? 0;
                $red = ($packedColor >> 16) & 0xFF;
                $green = ($packedColor >> 8) & 0xFF;
                $blue = $packedColor & 0xFF;
                $topColor = $this->colorHandle($isometricImage, $colorCache, $red, $green, $blue, 0);
                $isoX = ($worldX - $worldZ) + ($sourceHeight - 1);
                $depthOffset = $this->depthOffsetForHeight($worldY + 1);
                $isoY = (int) floor(($worldX + $worldZ) / 2) + $verticalDepthPadding - $depthOffset;
                $baseDepth = (($worldX + $worldZ) * 8192) + ($worldY * 4);

                if ($topExposed) {
                    $this->plotPixelIfCloser($isometricImage, $depthBuffer, $isoWidth, $isoX, $isoY, $topColor, $baseDepth + 3);
                }

                if ($eastExposed) {
                    $eastColor = $this->colorHandle(
                        $isometricImage,
                        $colorCache,
                        (int) round($red * 0.8),
                        (int) round($green * 0.8),
                        (int) round($blue * 0.8),
                        0
                    );

                    $this->plotPixelIfCloser($isometricImage, $depthBuffer, $isoWidth, $isoX + 1, $isoY + 1, $eastColor, $baseDepth + 2);
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

                    $this->plotPixelIfCloser($isometricImage, $depthBuffer, $isoWidth, $isoX - 1, $isoY + 1, $southColor, $baseDepth + 1);
                }

                if ($topExposed) {
                    $shadowTargetY = $columnYs[$index + 1] ?? null;

                    if (is_int($shadowTargetY) && ($worldY - $shadowTargetY) >= 2) {
                        $underlayColor = $columnColors[$index + 1] ?? 0;
                        $ur = ($underlayColor >> 16) & 0xFF;
                        $ug = ($underlayColor >> 8) & 0xFF;
                        $ub = $underlayColor & 0xFF;

                        if ($ur !== 0 || $ug !== 0 || $ub !== 0) {
                            $underlayDepthOffset = $this->depthOffsetForHeight($shadowTargetY + 1);
                            $underlayIsoY = (int) floor(($worldX + $worldZ) / 2) + $verticalDepthPadding - $underlayDepthOffset;
                            $shadowDepth = (($worldX + $worldZ) * 8192) + ($shadowTargetY * 4) + 4;
                            $shadowColor = $this->colorHandle(
                                $isometricImage,
                                $colorCache,
                                (int) round($ur * 0.45),
                                (int) round($ug * 0.45),
                                (int) round($ub * 0.45),
                                80
                            );

                            $this->plotPixelIfCloser($isometricImage, $depthBuffer, $isoWidth, $isoX, $underlayIsoY, $shadowColor, $shadowDepth);
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

    /**
     * @return array{0:array<int, array{ys:array<int, int>, colors:array<int, int>}>,1:?int,2:?int,3:int}
     */
    private function buildSolidColumns(string $regionFile, int $sourceWidth, int $sourceHeight): array
    {
        $columns = [];
        $minY = null;
        $maxY = null;
        $chunkCount = $this->minecraftBirdsEyeRenderer->iterateRegionSections(
            $regionFile,
            function (int $chunkX, int $chunkZ, array $chunkSections) use (&$columns, &$minY, &$maxY, $sourceWidth, $sourceHeight): void {
                foreach ($chunkSections as $sectionY => $section) {
                    $uniformPaletteIndex = $section['uniform_palette_index'];

                    if ($uniformPaletteIndex !== null && ($section['palette_is_air'][$uniformPaletteIndex] ?? true) === true) {
                        continue;
                    }

                    for ($localY = 0; $localY < 16; $localY++) {
                        $worldY = ((int) $sectionY * 16) + $localY;

                        for ($localZ = 0; $localZ < 16; $localZ++) {
                            $worldZ = ($chunkZ * 16) + $localZ;

                            if ($worldZ < 0 || $worldZ >= $sourceHeight) {
                                continue;
                            }

                            for ($localX = 0; $localX < 16; $localX++) {
                                $worldX = ($chunkX * 16) + $localX;

                                if ($worldX < 0 || $worldX >= $sourceWidth) {
                                    continue;
                                }

                                if ($uniformPaletteIndex !== null) {
                                    $paletteIndex = (int) $uniformPaletteIndex;
                                } else {
                                    $paletteIndex = $this->paletteIndexAt($section, $localX, $localZ, $localY);
                                }

                                if (($section['palette_is_air'][$paletteIndex] ?? true) === true) {
                                    continue;
                                }

                                $minY = $minY === null ? $worldY : min($minY, $worldY);
                                $maxY = $maxY === null ? $worldY : max($maxY, $worldY);

                                [$red, $green, $blue] = $section['palette_colors'][$paletteIndex] ?? [90, 90, 92];
                                $packedColor = (($red & 0xFF) << 16) | (($green & 0xFF) << 8) | ($blue & 0xFF);
                                $columnIndex = ($worldZ * $sourceWidth) + $worldX;

                                if (! isset($columns[$columnIndex])) {
                                    $columns[$columnIndex] = [
                                        'ys' => [],
                                        'colors' => [],
                                    ];
                                }

                                $columns[$columnIndex]['ys'][] = $worldY;
                                $columns[$columnIndex]['colors'][] = $packedColor;
                            }
                        }
                    }
                }
            }
        );

        foreach ($columns as &$column) {
            if (count($column['ys']) <= 1) {
                continue;
            }

            array_multisort($column['ys'], SORT_DESC, SORT_NUMERIC, $column['colors']);
        }

        unset($column);

        return [$columns, $minY, $maxY, $chunkCount];
    }

    /**
     * @param  array<int, int>  $ys
     */
    private function containsY(array $ys, int $target): bool
    {
        $low = 0;
        $high = count($ys) - 1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $value = $ys[$mid];

            if ($value === $target) {
                return true;
            }

            if ($value > $target) {
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return false;
    }

    private function depthOffsetForHeight(int $height): int
    {
        $clampedHeight = max(self::HEIGHT_BASELINE, min(self::HEIGHT_CEILING, $height));

        return (int) round(($clampedHeight - self::HEIGHT_BASELINE) * self::DEPTH_SCALE);
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
}

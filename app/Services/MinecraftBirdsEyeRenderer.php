<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class MinecraftBirdsEyeRenderer
{
    private const RENDER_METADATA_VERSION = 2;

    public function __construct(private Filesystem $files, private MinecraftRegionReader $minecraftRegionReader) {}

    /**
     * @return array{
     *     region_count:int,
     *     chunk_count:int,
     *     regions: array<int, array{
     *         region_file:string,
     *         file:string,
     *         relative_path:string,
     *         width_blocks:int,
     *         height_blocks:int,
     *         min_height:float,
     *         max_height:float,
     *         chunk_count:int
     *     }>
     * }
     */
    public function render(string $heightmapType = 'WORLD_SURFACE'): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to render images.');
        }

        $regionFiles = $this->minecraftRegionReader->listRegionFiles();

        if ($regionFiles === []) {
            throw new RuntimeException('No region files found in public/region.');
        }

        $regions = [];
        $totalChunks = 0;

        foreach ($regionFiles as $regionFile) {
            $regionRender = $this->renderRegion($regionFile, $heightmapType);

            if ($regionRender === null) {
                continue;
            }

            $regions[] = $regionRender;
            $totalChunks += $regionRender['chunk_count'];
        }

        if ($regions === []) {
            throw new RuntimeException('No readable modern chunk data found.');
        }

        usort($regions, fn (array $left, array $right): int => $left['region_file'] <=> $right['region_file']);

        return [
            'region_count' => count($regions),
            'chunk_count' => $totalChunks,
            'regions' => $regions,
        ];
    }

    /**
     * @return array{
     *     region_file:string,
     *     file:string,
     *     relative_path:string,
     *     width_blocks:int,
     *     height_blocks:int,
     *     min_height:float,
     *     max_height:float,
     *     chunk_count:int
     * }|null
     */
    public function renderRegion(string $regionFile, string $heightmapType = 'WORLD_SURFACE'): ?array
    {
        $regionPath = public_path('region'.DIRECTORY_SEPARATOR.$regionFile);

        if (! $this->files->exists($regionPath)) {
            throw new RuntimeException("Region file not found: {$regionFile}");
        }

        $binary = $this->files->get($regionPath);
        $rendered = $this->renderSingleRegion($regionFile, $binary, $heightmapType);

        if ($rendered === null) {
            return null;
        }

        $metadata = [
            'version' => self::RENDER_METADATA_VERSION,
            'heightmap_type' => $heightmapType,
            'source_modified_at' => $this->files->lastModified($regionPath),
            'rendered_at' => time(),
            'chunk_count' => $rendered['chunk_count'],
            'min_height' => $rendered['min_height'],
            'max_height' => $rendered['max_height'],
        ];
        $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $metadataPath = $this->renderMetadataPath($regionFile);
        $this->files->ensureDirectoryExists(dirname($metadataPath));
        $this->files->put($metadataPath, $metadataJson);

        return $rendered;
    }

    public function regionNeedsRendering(string $regionFile, string $heightmapType = 'WORLD_SURFACE'): bool
    {
        $regionPath = public_path('region'.DIRECTORY_SEPARATOR.$regionFile);
        $renderPath = public_path('maps/regions'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
        $metadataPath = $this->renderMetadataPath($regionFile);

        if (! $this->files->exists($regionPath) || ! $this->files->exists($renderPath) || ! $this->files->exists($metadataPath)) {
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
            || ($metadata['source_modified_at'] ?? null) !== $this->files->lastModified($regionPath);
    }

    /**
     * @return array{
     *     region_file:string,
     *     file:string,
     *     relative_path:string,
     *     width_blocks:int,
     *     height_blocks:int,
     *     min_height:float,
     *     max_height:float,
     *     chunk_count:int
     * }|null
     */
    private function renderSingleRegion(string $regionFile, string $binary, string $heightmapType): ?array
    {
        if (strlen($binary) < 8192) {
            return null;
        }

        $locationHeader = substr($binary, 0, 4096);
        $preparedChunks = [];
        $minHeight = null;
        $maxHeight = null;

        for ($index = 0; $index < 1024; $index++) {
            $locationEntry = substr($locationHeader, $index * 4, 4);
            $offset = unpack('N', "\x00".substr($locationEntry, 0, 3))[1];
            $sectorCount = ord($locationEntry[3]);

            if ($offset === 0 || $sectorCount === 0) {
                continue;
            }

            $chunkBinary = $this->readChunkPayload($binary, $offset);

            if ($chunkBinary === null) {
                continue;
            }

            try {
                $chunkData = $this->parseModernChunk($chunkBinary, $heightmapType);
            } catch (RuntimeException) {
                continue;
            }

            if ($chunkData === null) {
                continue;
            }

            $localChunkX = $index % 32;
            $localChunkZ = intdiv($index, 32);
            $preparedChunks[] = [
                'chunk_x' => $localChunkX,
                'chunk_z' => $localChunkZ,
                'heights' => $chunkData['heights'],
                'colors' => $chunkData['colors'],
            ];
            $chunkMinHeight = min($chunkData['heights']);
            $chunkMaxHeight = max($chunkData['heights']);
            $minHeight = $minHeight === null ? $chunkMinHeight : min($minHeight, $chunkMinHeight);
            $maxHeight = $maxHeight === null ? $chunkMaxHeight : max($maxHeight, $chunkMaxHeight);
        }

        if ($preparedChunks === [] || $minHeight === null || $maxHeight === null) {
            return null;
        }

        $widthBlocks = 32 * 16;
        $heightBlocks = 32 * 16;
        $outputRelativePath = 'maps/regions/'.str_replace('.mca', '.png', $regionFile);
        $outputPath = public_path($outputRelativePath);
        $this->files->ensureDirectoryExists(dirname($outputPath));
        $image = imagecreatetruecolor($widthBlocks, $heightBlocks);

        if ($image === false) {
            throw new RuntimeException('Unable to create output image.');
        }

        $backgroundColor = imagecolorallocate($image, 26, 26, 28);
        imagefill($image, 0, 0, $backgroundColor);

        $colorCache = [];
        $heightRange = max(1.0, $maxHeight - $minHeight);

        foreach ($preparedChunks as $chunk) {
            $basePixelX = $chunk['chunk_x'] * 16;
            $basePixelZ = $chunk['chunk_z'] * 16;
            $heights = $chunk['heights'];
            $colors = $chunk['colors'];

            for ($localZ = 0; $localZ < 16; $localZ++) {
                for ($localX = 0; $localX < 16; $localX++) {
                    $index = ($localZ * 16) + $localX;
                    $pixelX = $basePixelX + $localX;
                    $pixelZ = $basePixelZ + $localZ;
                    $height = $heights[$index];
                    $eastHeight = ($localX < 15) ? $heights[($localZ * 16) + ($localX + 1)] : $height;
                    $southHeight = ($localZ < 15) ? $heights[(($localZ + 1) * 16) + $localX] : $height;
                    [$red, $green, $blue] = $colors[$index];
                    $slope = (($height - $eastHeight) + ($height - $southHeight)) / 2;
                    $normalized = ($height - $minHeight) / $heightRange;
                    $heightBoost = 0.9 + ($normalized * 0.25);
                    $shadeFactor = max(0.65, min(1.25, (1 + ($slope / 14)) * $heightBoost));
                    $shadedRed = max(0, min(255, (int) round($red * $shadeFactor)));
                    $shadedGreen = max(0, min(255, (int) round($green * $shadeFactor)));
                    $shadedBlue = max(0, min(255, (int) round($blue * $shadeFactor)));
                    $colorKey = ($shadedRed << 16) | ($shadedGreen << 8) | $shadedBlue;

                    if (! array_key_exists($colorKey, $colorCache)) {
                        $colorCache[$colorKey] = imagecolorallocate($image, $shadedRed, $shadedGreen, $shadedBlue);
                    }

                    imagesetpixel($image, $pixelX, $pixelZ, $colorCache[$colorKey]);
                }
            }
        }

        imagepng($image, $outputPath);
        imagedestroy($image);
        $this->writeRegionHeightMap($regionFile, $preparedChunks);

        return [
            'region_file' => $regionFile,
            'file' => basename($outputPath),
            'relative_path' => $outputRelativePath,
            'width_blocks' => $widthBlocks,
            'height_blocks' => $heightBlocks,
            'min_height' => $minHeight,
            'max_height' => $maxHeight,
            'chunk_count' => count($preparedChunks),
        ];
    }

    /**
     * @return array{
     *     file:string,
     *     relative_path:string,
     *     chunk_x:int,
     *     chunk_z:int
     * }
     */
    public function renderChunk(int $worldChunkX, int $worldChunkZ, string $heightmapType = 'WORLD_SURFACE'): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to render images.');
        }

        $regionX = $this->floorDivide($worldChunkX, 32);
        $regionZ = $this->floorDivide($worldChunkZ, 32);
        $localChunkX = (($worldChunkX % 32) + 32) % 32;
        $localChunkZ = (($worldChunkZ % 32) + 32) % 32;
        $regionFile = sprintf('r.%d.%d.mca', $regionX, $regionZ);
        $regionPath = public_path('region'.DIRECTORY_SEPARATOR.$regionFile);

        if (! $this->files->exists($regionPath)) {
            throw new RuntimeException("Region file not found: {$regionFile}");
        }

        $binary = $this->files->get($regionPath);

        if (strlen($binary) < 8192) {
            throw new RuntimeException('Region file is invalid.');
        }

        $locationHeader = substr($binary, 0, 4096);
        $chunkData = null;

        // Fast path: expected slot for that chunk coordinate.
        $expectedIndex = ($localChunkZ * 32) + $localChunkX;
        $expectedEntry = substr($locationHeader, $expectedIndex * 4, 4);
        $expectedOffset = unpack('N', "\x00".substr($expectedEntry, 0, 3))[1];
        $expectedSectors = ord($expectedEntry[3]);

        if ($expectedOffset > 0 && $expectedSectors > 0) {
            $expectedBinary = $this->readChunkPayload($binary, $expectedOffset);

            if ($expectedBinary !== null) {
                $candidate = $this->parseModernChunk($expectedBinary, $heightmapType);

                if (
                    $candidate !== null
                    && (($candidate['chunk_x'] ?? $worldChunkX) === $worldChunkX)
                    && (($candidate['chunk_z'] ?? $worldChunkZ) === $worldChunkZ)
                ) {
                    $chunkData = $candidate;
                }
            }
        }

        // Fallback: scan region slots by true xPos/zPos in NBT.
        if ($chunkData === null) {
            for ($index = 0; $index < 1024; $index++) {
                $entry = substr($locationHeader, $index * 4, 4);
                $offset = unpack('N', "\x00".substr($entry, 0, 3))[1];
                $sectors = ord($entry[3]);

                if ($offset === 0 || $sectors === 0) {
                    continue;
                }

                $chunkBinary = $this->readChunkPayload($binary, $offset);

                if ($chunkBinary === null) {
                    continue;
                }

                $candidate = $this->parseModernChunk($chunkBinary, $heightmapType);

                if ($candidate === null) {
                    continue;
                }

                if (($candidate['chunk_x'] ?? null) === $worldChunkX && ($candidate['chunk_z'] ?? null) === $worldChunkZ) {
                    $chunkData = $candidate;
                    break;
                }
            }
        }

        if ($chunkData === null) {
            throw new RuntimeException('Requested chunk not found by xPos/zPos in region data.');
        }

        $image = imagecreatetruecolor(16, 16);

        if ($image === false) {
            throw new RuntimeException('Unable to allocate chunk image.');
        }

        $cache = [];
        $heights = $chunkData['heights'];
        $colors = $chunkData['colors'];
        $minHeight = min($heights);
        $maxHeight = max($heights);
        $heightRange = max(1.0, $maxHeight - $minHeight);

        for ($localZ = 0; $localZ < 16; $localZ++) {
            for ($localX = 0; $localX < 16; $localX++) {
                $i = ($localZ * 16) + $localX;
                $height = $heights[$i];
                $eastHeight = $heights[($localZ * 16) + min($localX + 1, 15)];
                $southHeight = $heights[(min($localZ + 1, 15) * 16) + $localX];
                [$red, $green, $blue] = $colors[$i];
                $slope = (($height - $eastHeight) + ($height - $southHeight)) / 2;
                $normalized = ($height - $minHeight) / $heightRange;
                $shadeFactor = max(0.7, min(1.2, (1 + ($slope / 14)) * (0.92 + ($normalized * 0.2))));
                $r = max(0, min(255, (int) round($red * $shadeFactor)));
                $g = max(0, min(255, (int) round($green * $shadeFactor)));
                $b = max(0, min(255, (int) round($blue * $shadeFactor)));
                $key = ($r << 16) | ($g << 8) | $b;

                if (! array_key_exists($key, $cache)) {
                    $cache[$key] = imagecolorallocate($image, $r, $g, $b);
                }

                imagesetpixel($image, $localX, $localZ, $cache[$key]);
            }
        }

        $relativePath = sprintf('maps/chunks/chunk_%d_%d.png', $worldChunkX, $worldChunkZ);
        $outputPath = public_path($relativePath);
        $this->files->ensureDirectoryExists(dirname($outputPath));
        imagepng($image, $outputPath);
        imagedestroy($image);

        return [
            'file' => basename($outputPath),
            'relative_path' => $relativePath,
            'chunk_x' => $worldChunkX,
            'chunk_z' => $worldChunkZ,
        ];
    }

    /**
     * @return array{
     *     heights: array<int, int>,
     *     colors: array<int, array{0:int,1:int,2:int}>,
     *     chunk_x?: int,
     *     chunk_z?: int
     * }|null
     */
    private function parseModernChunk(string $chunkNbtBinary, string $heightmapType): ?array
    {
        $cursor = 0;

        if ($this->readUnsignedByte($chunkNbtBinary, $cursor) !== 10) {
            return null;
        }

        $this->readString($chunkNbtBinary, $cursor);
        $heightmaps = [];
        $sections = [];
        $chunkX = null;
        $chunkZ = null;

        while (true) {
            $tagType = $this->readUnsignedByte($chunkNbtBinary, $cursor);

            if ($tagType === 0) {
                break;
            }

            $tagName = $this->readString($chunkNbtBinary, $cursor);

            if ($tagName === 'Heightmaps' && $tagType === 10) {
                $heightmaps = $this->readHeightmapsCompoundRaw($chunkNbtBinary, $cursor);

                continue;
            }

            if ($tagName === 'xPos' && $tagType === 3) {
                $chunkX = $this->readSignedInt($chunkNbtBinary, $cursor);

                continue;
            }

            if ($tagName === 'zPos' && $tagType === 3) {
                $chunkZ = $this->readSignedInt($chunkNbtBinary, $cursor);

                continue;
            }

            if ($tagName === 'sections' && $tagType === 9) {
                $sections = $this->readSectionsList($chunkNbtBinary, $cursor);

                continue;
            }

            if ($tagName === 'Level' && $tagType === 10) {
                [$levelHeightmaps, $levelSections, $levelX, $levelZ] = $this->readLevelCompoundModern($chunkNbtBinary, $cursor);
                $heightmaps = array_merge($heightmaps, $levelHeightmaps);

                if ($sections === [] && $levelSections !== []) {
                    $sections = $levelSections;
                }

                if ($chunkX === null && $levelX !== null) {
                    $chunkX = $levelX;
                }

                if ($chunkZ === null && $levelZ !== null) {
                    $chunkZ = $levelZ;
                }

                continue;
            }

            $this->skipTagPayload($tagType, $chunkNbtBinary, $cursor);
        }

        if ($sections === []) {
            return null;
        }

        [$heights, $colors] = $this->resolveTopSurface($sections, $heightmaps[$heightmapType] ?? null);

        return [
            'heights' => $heights,
            'colors' => $colors,
            'chunk_x' => $chunkX,
            'chunk_z' => $chunkZ,
        ];
    }

    /**
     * @return array{0: array<string, array<int, string>>, 1: array<int, array<string, mixed>>, 2:?int, 3:?int}
     */
    private function readLevelCompoundModern(string $binary, int &$cursor): array
    {
        $heightmaps = [];
        $sections = [];
        $chunkX = null;
        $chunkZ = null;

        while (true) {
            $tagType = $this->readUnsignedByte($binary, $cursor);

            if ($tagType === 0) {
                break;
            }

            $tagName = $this->readString($binary, $cursor);

            if ($tagName === 'Heightmaps' && $tagType === 10) {
                $heightmaps = $this->readHeightmapsCompoundRaw($binary, $cursor);

                continue;
            }

            if ($tagName === 'xPos' && $tagType === 3) {
                $chunkX = $this->readSignedInt($binary, $cursor);

                continue;
            }

            if ($tagName === 'zPos' && $tagType === 3) {
                $chunkZ = $this->readSignedInt($binary, $cursor);

                continue;
            }

            if (($tagName === 'Sections' || $tagName === 'sections') && $tagType === 9) {
                $sections = $this->readSectionsList($binary, $cursor);

                continue;
            }

            $this->skipTagPayload($tagType, $binary, $cursor);
        }

        return [$heightmaps, $sections, $chunkX, $chunkZ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function readHeightmapsCompoundRaw(string $binary, int &$cursor): array
    {
        $heightmaps = [];

        while (true) {
            $tagType = $this->readUnsignedByte($binary, $cursor);

            if ($tagType === 0) {
                break;
            }

            $tagName = $this->readString($binary, $cursor);

            if ($tagType === 12) {
                $heightmaps[$tagName] = $this->readLongArrayRaw($binary, $cursor);

                continue;
            }

            $this->skipTagPayload($tagType, $binary, $cursor);
        }

        return $heightmaps;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readSectionsList(string $binary, int &$cursor): array
    {
        $itemType = $this->readUnsignedByte($binary, $cursor);
        $length = $this->readSignedInt($binary, $cursor);
        $sections = [];

        for ($index = 0; $index < $length; $index++) {
            if ($itemType !== 10) {
                $this->skipTagPayload($itemType, $binary, $cursor);

                continue;
            }

            $section = $this->readSectionCompound($binary, $cursor);

            if ($section === null) {
                continue;
            }

            $sections[$section['y']] = [
                'palette' => $section['palette'],
                'palette_is_air' => $section['palette_is_air'],
                'palette_colors' => $section['palette_colors'],
                'uniform_palette_index' => $section['uniform_palette_index'],
                'block_data_words' => $section['block_data_words'],
                'bits_per_entry' => $section['bits_per_entry'],
                'values_per_long' => $section['values_per_long'],
                'uses_padded_layout' => $section['uses_padded_layout'],
            ];
        }

        return $sections;
    }

    /**
     * @return array{
     *     y:int,
     *     palette: array<int, string>,
     *     palette_is_air: array<int, bool>,
     *     palette_colors: array<int, array{0:int,1:int,2:int}>,
     *     uniform_palette_index:?int,
     *     block_data_words: array<int, array{hi:int,lo:int}>,
     *     bits_per_entry:?int,
     *     values_per_long:?int,
     *     uses_padded_layout:bool
     * }|null
     */
    private function readSectionCompound(string $binary, int &$cursor): ?array
    {
        $sectionY = null;
        $palette = [];
        $blockData = [];

        while (true) {
            $tagType = $this->readUnsignedByte($binary, $cursor);

            if ($tagType === 0) {
                break;
            }

            $tagName = $this->readString($binary, $cursor);

            if ($tagName === 'Y' && $tagType === 1) {
                $sectionY = $this->readSignedByte($binary, $cursor);

                continue;
            }

            if ($tagName === 'block_states' && $tagType === 10) {
                [$palette, $blockData] = $this->readBlockStatesCompound($binary, $cursor);

                continue;
            }

            $this->skipTagPayload($tagType, $binary, $cursor);
        }

        if ($sectionY === null || $palette === []) {
            return null;
        }

        $paletteIsAir = [];
        $paletteColors = [];

        foreach ($palette as $index => $blockName) {
            $paletteIsAir[$index] = $this->isAirLikeBlock($blockName);
            $paletteColors[$index] = $this->colorForBlock($blockName);
        }

        $bitsPerEntry = null;
        $valuesPerLong = null;
        $usesPaddedLayout = false;
        $blockDataWords = [];
        $uniformPaletteIndex = null;

        if (count($palette) === 1) {
            $uniformPaletteIndex = 0;
        } elseif ($blockData !== []) {
            $bitsPerEntry = max(4, (int) ceil(log(count($palette), 2)));
            $valuesPerLong = intdiv(64, $bitsPerEntry);
            $blockDataWords = $this->unpackLongWords($blockData);
            $usesPaddedLayout = $valuesPerLong > 0 && (count($blockDataWords) * $valuesPerLong) >= 4096;
        }

        return [
            'y' => $sectionY,
            'palette' => $palette,
            'palette_is_air' => $paletteIsAir,
            'palette_colors' => $paletteColors,
            'uniform_palette_index' => $uniformPaletteIndex,
            'block_data_words' => $blockDataWords,
            'bits_per_entry' => $bitsPerEntry,
            'values_per_long' => $valuesPerLong,
            'uses_padded_layout' => $usesPaddedLayout,
        ];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function readBlockStatesCompound(string $binary, int &$cursor): array
    {
        $palette = [];
        $data = [];

        while (true) {
            $tagType = $this->readUnsignedByte($binary, $cursor);

            if ($tagType === 0) {
                break;
            }

            $tagName = $this->readString($binary, $cursor);

            if ($tagName === 'palette' && $tagType === 9) {
                $palette = $this->readPaletteNamesList($binary, $cursor);

                continue;
            }

            if ($tagName === 'data' && $tagType === 12) {
                $data = $this->readLongArrayRaw($binary, $cursor);

                continue;
            }

            $this->skipTagPayload($tagType, $binary, $cursor);
        }

        return [$palette, $data];
    }

    /**
     * @return array<int, string>
     */
    private function readPaletteNamesList(string $binary, int &$cursor): array
    {
        $itemType = $this->readUnsignedByte($binary, $cursor);
        $length = $this->readSignedInt($binary, $cursor);
        $names = [];

        for ($index = 0; $index < $length; $index++) {
            if ($itemType !== 10) {
                $this->skipTagPayload($itemType, $binary, $cursor);

                continue;
            }

            $blockName = null;

            while (true) {
                $tagType = $this->readUnsignedByte($binary, $cursor);

                if ($tagType === 0) {
                    break;
                }

                $tagName = $this->readString($binary, $cursor);

                if ($tagName === 'Name' && $tagType === 8) {
                    $blockName = $this->readString($binary, $cursor);

                    continue;
                }

                $this->skipTagPayload($tagType, $binary, $cursor);
            }

            $names[] = $blockName ?? 'minecraft:air';
        }

        return $names;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<int, string>|null  $heightmap
     * @return array{0: array<int, int>, 1: array<int, array{0:int,1:int,2:int}>}
     */
    private function resolveTopSurface(array $sections, ?array $heightmap): array
    {
        $sectionYs = array_keys($sections);
        sort($sectionYs);
        $minSectionY = (int) min($sectionYs);
        $maxSectionY = (int) max($sectionYs);
        $minY = $minSectionY * 16;
        $maxY = ($maxSectionY * 16) + 15;
        $heights = array_fill(0, 256, 0);
        $colors = array_fill(0, 256, [90, 90, 92]);
        $heightHints = $this->decodeHeightHints($heightmap, $minY, $maxY);

        for ($localZ = 0; $localZ < 16; $localZ++) {
            for ($localX = 0; $localX < 16; $localX++) {
                $surfaceIndex = ($localZ * 16) + $localX;
                $startY = $heightHints[$surfaceIndex] ?? $maxY;
                $startSectionY = $this->floorDivide($startY, 16);
                $startSectionY = max($minSectionY, min($maxSectionY, $startSectionY));

                for ($sectionY = $startSectionY; $sectionY >= $minSectionY; $sectionY--) {
                    if (! isset($sections[$sectionY])) {
                        continue;
                    }

                    $section = $sections[$sectionY];
                    $localTopY = $sectionY === $startSectionY ? ($startY - ($sectionY * 16)) : 15;

                    if ($localTopY < 0) {
                        continue;
                    }

                    if ($section['uniform_palette_index'] !== null) {
                        $paletteIndex = (int) $section['uniform_palette_index'];

                        if (($section['palette_is_air'][$paletteIndex] ?? true) === true) {
                            continue;
                        }

                        $heights[$surfaceIndex] = ($sectionY * 16) + $localTopY + 1;
                        $colors[$surfaceIndex] = $section['palette_colors'][$paletteIndex] ?? [90, 90, 92];
                        break;
                    }

                    for ($yInSection = $localTopY; $yInSection >= 0; $yInSection--) {
                        $paletteIndex = $this->paletteIndexAt($section, $localX, $localZ, $yInSection);

                        if (($section['palette_is_air'][$paletteIndex] ?? true) === true) {
                            continue;
                        }

                        $heights[$surfaceIndex] = ($sectionY * 16) + $yInSection + 1;
                        $colors[$surfaceIndex] = $section['palette_colors'][$paletteIndex] ?? [90, 90, 92];
                        break 2;
                    }
                }
            }
        }

        return [$heights, $colors];
    }

    /**
     * @return array<int, array{0:int,1:int,2:int}>
     */
    private function decodeHeightHints(?array $heightmap, int $minY, int $maxY): array
    {
        if ($heightmap === null || $heightmap === []) {
            return [];
        }

        $decodedHeights = $this->decodePackedValues($heightmap, 256, 9);

        if (count($decodedHeights) !== 256) {
            return [];
        }

        $hints = [];

        foreach ($decodedHeights as $index => $heightValue) {
            $normalized = $heightValue;

            if ($normalized < $minY || $normalized > $maxY) {
                $offsetHeight = $heightValue - 64;

                if ($offsetHeight >= $minY && $offsetHeight <= $maxY) {
                    $normalized = $offsetHeight;
                }
            }

            $hints[$index] = max($minY, min($maxY, $normalized));
        }

        return $hints;
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
     * @return array{0:int,1:int,2:int}
     */
    private function colorForBlock(string $blockName): array
    {
        return match ($blockName) {
            'minecraft:water', 'minecraft:bubble_column' => [52, 98, 182],
            'minecraft:lava' => [238, 108, 34],
            'minecraft:grass_block', 'minecraft:moss_block' => [106, 167, 69],
            'minecraft:dirt', 'minecraft:coarse_dirt', 'minecraft:rooted_dirt', 'minecraft:podzol', 'minecraft:mud' => [122, 90, 62],
            'minecraft:sand', 'minecraft:red_sand', 'minecraft:sandstone', 'minecraft:red_sandstone' => [210, 194, 140],
            'minecraft:stone', 'minecraft:andesite', 'minecraft:diorite', 'minecraft:granite', 'minecraft:cobblestone' => [125, 125, 125],
            'minecraft:deepslate', 'minecraft:cobbled_deepslate' => [78, 78, 84],
            'minecraft:snow', 'minecraft:snow_block', 'minecraft:powder_snow' => [240, 243, 247],
            default => $this->colorForBlockByHeuristic($blockName),
        };
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function colorForBlockByHeuristic(string $blockName): array
    {
        if ($this->isAirLikeBlock($blockName)) {
            return [90, 90, 92];
        }

        if (str_contains($blockName, 'leaves')) {
            return [76, 136, 60];
        }

        if (str_contains($blockName, 'log') || str_contains($blockName, 'wood')) {
            return [118, 90, 58];
        }

        if (str_contains($blockName, 'planks')) {
            return [162, 126, 89];
        }

        if (str_contains($blockName, 'grass') || str_contains($blockName, 'fern')) {
            return [104, 164, 70];
        }

        if (str_contains($blockName, 'ice')) {
            return [164, 205, 234];
        }

        if (str_contains($blockName, 'snow')) {
            return [239, 241, 245];
        }

        if (str_contains($blockName, 'sand')) {
            return [212, 197, 141];
        }

        if (str_contains($blockName, 'terracotta') || str_contains($blockName, 'clay')) {
            return [154, 112, 91];
        }

        if (str_contains($blockName, 'dirt') || str_contains($blockName, 'mud')) {
            return [122, 90, 62];
        }

        if (str_contains($blockName, 'stone') || str_contains($blockName, 'ore') || str_contains($blockName, 'slate')) {
            return [126, 126, 126];
        }

        return [120, 120, 122];
    }

    private function isAirLikeBlock(string $blockName): bool
    {
        return str_contains($blockName, 'air');
    }

    private function floorDivide(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        if (($value % $divisor) !== 0 && $value < 0) {
            return $quotient - 1;
        }

        return $quotient;
    }

    private function readChunkPayload(string $regionBinary, int $offsetSectors): ?string
    {
        $byteOffset = $offsetSectors * 4096;

        if ($byteOffset + 5 > strlen($regionBinary)) {
            return null;
        }

        $storedLength = unpack('N', substr($regionBinary, $byteOffset, 4))[1];
        $compressionType = ord($regionBinary[$byteOffset + 4]);
        $compressed = substr($regionBinary, $byteOffset + 5, max(0, $storedLength - 1));

        if ($compressionType === 1) {
            $decoded = gzdecode($compressed);

            return is_string($decoded) ? $decoded : null;
        }

        if ($compressionType === 2) {
            $decoded = zlib_decode($compressed);

            return is_string($decoded) ? $decoded : null;
        }

        if ($compressionType === 3) {
            return $compressed;
        }

        return null;
    }

    private function skipTagPayload(int $tagType, string $binary, int &$cursor): void
    {
        if ($tagType === 1) {
            $this->readBytes($binary, $cursor, 1);

            return;
        }

        if ($tagType === 2) {
            $this->readBytes($binary, $cursor, 2);

            return;
        }

        if ($tagType === 3 || $tagType === 5) {
            $this->readBytes($binary, $cursor, 4);

            return;
        }

        if ($tagType === 4 || $tagType === 6) {
            $this->readBytes($binary, $cursor, 8);

            return;
        }

        if ($tagType === 7) {
            $length = $this->readSignedInt($binary, $cursor);
            $this->readBytes($binary, $cursor, max(0, $length));

            return;
        }

        if ($tagType === 8) {
            $this->readString($binary, $cursor);

            return;
        }

        if ($tagType === 9) {
            $itemType = $this->readUnsignedByte($binary, $cursor);
            $length = $this->readSignedInt($binary, $cursor);

            for ($index = 0; $index < $length; $index++) {
                $this->skipTagPayload($itemType, $binary, $cursor);
            }

            return;
        }

        if ($tagType === 10) {
            while (true) {
                $nestedType = $this->readUnsignedByte($binary, $cursor);

                if ($nestedType === 0) {
                    break;
                }

                $this->readString($binary, $cursor);
                $this->skipTagPayload($nestedType, $binary, $cursor);
            }

            return;
        }

        if ($tagType === 11) {
            $length = $this->readSignedInt($binary, $cursor);
            $this->readBytes($binary, $cursor, max(0, $length * 4));

            return;
        }

        if ($tagType === 12) {
            $length = $this->readSignedInt($binary, $cursor);
            $this->readBytes($binary, $cursor, max(0, $length * 8));
        }
    }

    /**
     * @return array<int, string>
     */
    private function readLongArrayRaw(string $binary, int &$cursor): array
    {
        $length = $this->readSignedInt($binary, $cursor);
        $longs = [];

        for ($index = 0; $index < $length; $index++) {
            $longs[] = $this->readBytes($binary, $cursor, 8);
        }

        return $longs;
    }

    /**
     * @param  array<int, string>  $longBytes
     * @return array<int, int>
     */
    private function decodePackedValues(array $longBytes, int $entryCount, ?int $bitsPerEntry = null): array
    {
        if ($longBytes === []) {
            return [];
        }

        if ($bitsPerEntry === null) {
            $bitsPerEntry = max(1, intdiv(count($longBytes) * 64, $entryCount));
        }

        $longWords = $this->unpackLongWords($longBytes);
        $valuesPerLong = intdiv(64, $bitsPerEntry);

        if ($valuesPerLong > 0 && (count($longWords) * $valuesPerLong) >= $entryCount) {
            return $this->decodePaddedPackedValues($longWords, $entryCount, $bitsPerEntry, $valuesPerLong);
        }

        return $this->decodeContiguousPackedValues($longWords, $entryCount, $bitsPerEntry);
    }

    /**
     * @param  array<int, array{hi:int,lo:int}>  $longWords
     * @return array<int, int>
     */
    private function decodePaddedPackedValues(array $longWords, int $entryCount, int $bitsPerEntry, int $valuesPerLong): array
    {
        $values = [];

        for ($entryIndex = 0; $entryIndex < $entryCount; $entryIndex++) {
            $longIndex = intdiv($entryIndex, $valuesPerLong);
            $indexInLong = $entryIndex % $valuesPerLong;
            $bitOffset = $indexInLong * $bitsPerEntry;
            $values[] = $this->readPackedBits($longWords, $longIndex, $bitOffset, $bitsPerEntry);
        }

        return $values;
    }

    /**
     * @param  array<int, array{hi:int,lo:int}>  $longWords
     * @return array<int, int>
     */
    private function decodeContiguousPackedValues(array $longWords, int $entryCount, int $bitsPerEntry): array
    {
        $values = [];

        for ($entryIndex = 0; $entryIndex < $entryCount; $entryIndex++) {
            $startBit = $entryIndex * $bitsPerEntry;
            $longIndex = intdiv($startBit, 64);
            $bitOffset = $startBit % 64;
            $values[] = $this->readPackedBits($longWords, $longIndex, $bitOffset, $bitsPerEntry);
        }

        return $values;
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
     * @param  array<int, string>  $longBytes
     * @return array<int, array{hi:int,lo:int}>
     */
    private function unpackLongWords(array $longBytes): array
    {
        $longWords = [];

        foreach ($longBytes as $bytes) {
            $parts = unpack('Nhi/Nlo', $bytes);

            $longWords[] = [
                'hi' => (int) $parts['hi'],
                'lo' => (int) $parts['lo'],
            ];
        }

        return $longWords;
    }

    private function readSignedByte(string $binary, int &$cursor): int
    {
        $value = $this->readUnsignedByte($binary, $cursor);

        if ($value >= 0x80) {
            return $value - 0x100;
        }

        return $value;
    }

    private function readUnsignedByte(string $binary, int &$cursor): int
    {
        if (! isset($binary[$cursor])) {
            throw new RuntimeException('Unexpected end of NBT data.');
        }

        $byte = ord($binary[$cursor]);
        $cursor++;

        return $byte;
    }

    private function readSignedInt(string $binary, int &$cursor): int
    {
        $raw = unpack('N', $this->readBytes($binary, $cursor, 4))[1];

        if ($raw >= 0x80000000) {
            return $raw - 0x100000000;
        }

        return $raw;
    }

    private function readString(string $binary, int &$cursor): string
    {
        $length = unpack('n', $this->readBytes($binary, $cursor, 2))[1];

        return $this->readBytes($binary, $cursor, $length);
    }

    private function readBytes(string $binary, int &$cursor, int $length): string
    {
        $chunk = substr($binary, $cursor, $length);

        if (strlen($chunk) !== $length) {
            throw new RuntimeException('Unexpected end of NBT data.');
        }

        $cursor += $length;

        return $chunk;
    }

    private function renderMetadataPath(string $regionFile): string
    {
        return public_path('maps/regions'.DIRECTORY_SEPARATOR.'.meta'.DIRECTORY_SEPARATOR.str_replace('.mca', '.json', $regionFile));
    }

    /**
     * @param  array<int, array{chunk_x:int,chunk_z:int,heights:array<int, int>,colors:array<int, array{0:int,1:int,2:int}>}>  $preparedChunks
     */
    private function writeRegionHeightMap(string $regionFile, array $preparedChunks): void
    {
        $widthBlocks = 32 * 16;
        $heightBlocks = 32 * 16;
        $heightImage = imagecreatetruecolor($widthBlocks, $heightBlocks);

        if ($heightImage === false) {
            throw new RuntimeException('Unable to allocate region height map image.');
        }

        foreach ($preparedChunks as $chunk) {
            $basePixelX = $chunk['chunk_x'] * 16;
            $basePixelZ = $chunk['chunk_z'] * 16;
            $heights = $chunk['heights'];

            for ($localZ = 0; $localZ < 16; $localZ++) {
                for ($localX = 0; $localX < 16; $localX++) {
                    $index = ($localZ * 16) + $localX;
                    $heightValue = $heights[$index];
                    $encodedHeight = $heightValue + 32768;
                    $red = ($encodedHeight >> 8) & 0xFF;
                    $green = $encodedHeight & 0xFF;
                    $color = ($red << 16) | ($green << 8);
                    imagesetpixel($heightImage, $basePixelX + $localX, $basePixelZ + $localZ, $color);
                }
            }
        }

        $heightMapPath = $this->regionHeightMapPath($regionFile);
        $this->files->ensureDirectoryExists(dirname($heightMapPath));
        imagepng($heightImage, $heightMapPath);
        imagedestroy($heightImage);
    }

    private function regionHeightMapPath(string $regionFile): string
    {
        return public_path('maps/regions'.DIRECTORY_SEPARATOR.'.heights'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }
}

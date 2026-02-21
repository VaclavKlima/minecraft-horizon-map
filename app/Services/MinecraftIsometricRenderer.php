<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MinecraftIsometricRenderer
{
    private const RENDER_METADATA_VERSION = 18;

    public function __construct(
        private Filesystem $files,
        private MinecraftBirdsEyeRenderer $minecraftBirdsEyeRenderer,
        private IsometricNativeRenderer $isometricNativeRenderer
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
        $profileEnabled = (bool) config('render.isometric_profile_enabled', false);
        $renderStartedAt = microtime(true);
        $sourceWidth = 32 * 16;
        $sourceHeight = 32 * 16;
        $parseStartedAt = microtime(true);
        [$chunks, $chunkCount] = $this->buildSectionSnapshotForNative($regionFile, $sourceWidth, $sourceHeight);
        $parseElapsedMs = (int) round((microtime(true) - $parseStartedAt) * 1000);

        if ($chunkCount === 0 || $chunks === []) {
            return null;
        }

        $nativeStartedAt = microtime(true);
        $nativeRender = $this->isometricNativeRenderer->renderFromSections(
            $regionFile,
            $heightmapType,
            $chunks,
            $sourceWidth,
            $sourceHeight
        );
        $nativeElapsedMs = (int) round((microtime(true) - $nativeStartedAt) * 1000);

        if ($nativeRender !== null) {
            $this->persistRenderMetadata($regionFile, $heightmapType);

            if ($profileEnabled) {
                Log::info('Isometric region render timings (native).', [
                    'region_file' => $regionFile,
                    'parse_sections_ms' => $parseElapsedMs,
                    'native_render_ms' => $nativeElapsedMs,
                    'total_ms' => (int) round((microtime(true) - $renderStartedAt) * 1000),
                ]);
            }

            return $nativeRender;
        }

        throw new RuntimeException(
            sprintf(
                'Native isometric rendering failed for %s (parse_sections_ms=%d, native_attempt_ms=%d).',
                $regionFile,
                $parseElapsedMs,
                $nativeElapsedMs
            )
        );
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
            || ($metadata['source_modified_at'] ?? null) !== $this->files->lastModified($sourcePath)
            || ($metadata['source_size_bytes'] ?? null) !== $this->files->size($sourcePath)
            || ($metadata['source_changed_at'] ?? null) !== $this->sourceChangedAt($sourcePath);
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

    private function persistRenderMetadata(string $regionFile, string $heightmapType): void
    {
        $metadata = [
            'version' => self::RENDER_METADATA_VERSION,
            'heightmap_type' => $heightmapType,
            'source_modified_at' => $this->files->lastModified($this->regionPath($regionFile)),
            'source_size_bytes' => $this->files->size($this->regionPath($regionFile)),
            'source_changed_at' => $this->sourceChangedAt($this->regionPath($regionFile)),
            'rendered_at' => time(),
        ];
        $metadataPath = $this->renderMetadataPath($regionFile);
        $this->files->ensureDirectoryExists(dirname($metadataPath));
        $this->files->put($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *   0:array<int, array{chunk_x:int,chunk_z:int,sections:array<int, array<string, mixed>>}>,
     *   1:int
     * }
     */
    private function buildSectionSnapshotForNative(string $regionFile, int $sourceWidth, int $sourceHeight): array
    {
        $parsedChunks = [];
        $chunkCount = $this->minecraftBirdsEyeRenderer->iterateRegionSections(
            $regionFile,
            function (int $chunkX, int $chunkZ, array $chunkSections) use (&$parsedChunks, $sourceWidth, $sourceHeight): void {
                $chunkBaseX = $chunkX * 16;
                $chunkBaseZ = $chunkZ * 16;
                $minLocalX = max(0, -$chunkBaseX);
                $maxLocalX = min(15, ($sourceWidth - 1) - $chunkBaseX);
                $minLocalZ = max(0, -$chunkBaseZ);
                $maxLocalZ = min(15, ($sourceHeight - 1) - $chunkBaseZ);

                if ($minLocalX > $maxLocalX || $minLocalZ > $maxLocalZ) {
                    return;
                }

                $parsedChunks[] = [
                    'chunk_x' => $chunkX,
                    'chunk_z' => $chunkZ,
                    'sections' => $this->normalizeSectionsForNative($chunkSections),
                ];
            }
        );

        if ($chunkCount === 0 || $parsedChunks === []) {
            return [[], 0];
        }

        return [$parsedChunks, $chunkCount];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunkSections
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSectionsForNative(array $chunkSections): array
    {
        $normalizedSections = [];

        foreach ($chunkSections as $sectionY => $section) {
            $normalizedSections[] = [
                'section_y' => (int) $sectionY,
                'palette_is_air' => $section['palette_is_air'],
                'palette_is_water' => $section['palette_is_water'],
                'palette_uses_grass_tint' => $section['palette_uses_grass_tint'],
                'palette_uses_foliage_tint' => $section['palette_uses_foliage_tint'],
                'palette_colors' => $section['palette_colors'],
                'uniform_palette_index' => $section['uniform_palette_index'],
                'block_data_words' => $section['block_data_words'],
                'bits_per_entry' => $section['bits_per_entry'],
                'values_per_long' => $section['values_per_long'],
                'uses_padded_layout' => $section['uses_padded_layout'],
                'biome_palette_tints' => $section['biome_palette_tints'],
                'biome_uniform_palette_index' => $section['biome_uniform_palette_index'],
                'biome_data_words' => $section['biome_data_words'],
                'biome_bits_per_entry' => $section['biome_bits_per_entry'],
                'biome_values_per_long' => $section['biome_values_per_long'],
                'biome_uses_padded_layout' => $section['biome_uses_padded_layout'],
            ];
        }

        return $normalizedSections;
    }

    private function sourceChangedAt(string $sourcePath): int
    {
        $changedAt = @filectime($sourcePath);

        if (! is_int($changedAt)) {
            return $this->files->lastModified($sourcePath);
        }

        return $changedAt;
    }
}

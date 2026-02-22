<?php

namespace App\Services;

use App\Jobs\RenderRegionMapImageJob;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

class DispatchBirdsEyeMapBatch
{
    public function __construct(
        private MinecraftRegionReader $minecraftRegionReader,
        private MinecraftBirdsEyeRenderer $minecraftBirdsEyeRenderer
    ) {}

    /**
     * @return array{batch_id:string,region_count:int,message:string}
     */
    public function dispatch(string $heightmapType = 'WORLD_SURFACE', ?array $priorityContext = null): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to render map images.');
        }

        $regionFiles = $this->minecraftRegionReader->listRegionFiles();

        if ($regionFiles === []) {
            throw new RuntimeException('No region files found in public/region.');
        }

        $regionsToQueue = array_values(array_filter(
            $regionFiles,
            fn (string $regionFile): bool => $this->minecraftBirdsEyeRenderer->regionNeedsRendering($regionFile, $heightmapType)
        ));
        $regionsToQueue = $this->prioritizeRegions($regionsToQueue, $priorityContext);

        if ($regionsToQueue === []) {
            return [
                'batch_id' => '',
                'region_count' => 0,
                'message' => 'No changed regions detected.',
            ];
        }

        $jobs = array_map(
            fn (string $regionFile): RenderRegionMapImageJob => new RenderRegionMapImageJob($regionFile, $heightmapType),
            $regionsToQueue
        );

        $batch = Bus::batch($jobs)
            ->name('Render Minecraft birds-eye map')
            ->allowFailures()
            ->dispatch();

        return [
            'batch_id' => $batch->id,
            'region_count' => count($regionsToQueue),
            'message' => 'Queued map generation jobs.',
        ];
    }

    /**
     * @param  array<int, string>  $regions
     * @param  array<string, int|null>|null  $priorityContext
     * @return array<int, string>
     */
    private function prioritizeRegions(array $regions, ?array $priorityContext): array
    {
        if ($regions === [] || $priorityContext === null) {
            return $regions;
        }

        $focusX = $priorityContext['focus_world_x'] ?? null;
        $focusZ = $priorityContext['focus_world_z'] ?? null;

        if (! is_int($focusX) || ! is_int($focusZ)) {
            return $regions;
        }

        $focusRegionX = $this->worldToRegionCoordinate($focusX);
        $focusRegionZ = $this->worldToRegionCoordinate($focusZ);
        $priorityRegions = array_values(array_filter(
            array_map('strval', (array) ($priorityContext['priority_regions'] ?? [])),
            static fn (string $region): bool => $region !== ''
        ));
        $priorityOrder = array_flip($priorityRegions);
        $viewportMinX = $priorityContext['viewport_min_world_x'] ?? null;
        $viewportMinZ = $priorityContext['viewport_min_world_z'] ?? null;
        $viewportMaxX = $priorityContext['viewport_max_world_x'] ?? null;
        $viewportMaxZ = $priorityContext['viewport_max_world_z'] ?? null;

        usort($regions, function (string $left, string $right) use (
            $priorityOrder,
            $focusRegionX,
            $focusRegionZ,
            $viewportMinX,
            $viewportMinZ,
            $viewportMaxX,
            $viewportMaxZ
        ): int {
            $leftPriority = $priorityOrder[$left] ?? null;
            $rightPriority = $priorityOrder[$right] ?? null;

            if ($leftPriority !== null || $rightPriority !== null) {
                if ($leftPriority === null) {
                    return 1;
                }

                if ($rightPriority === null) {
                    return -1;
                }

                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }
            }

            [$leftRegionX, $leftRegionZ] = $this->parseRegionCoordinates($left);
            [$rightRegionX, $rightRegionZ] = $this->parseRegionCoordinates($right);

            $leftIntersectsViewport = $this->regionIntersectsViewport($leftRegionX, $leftRegionZ, $viewportMinX, $viewportMinZ, $viewportMaxX, $viewportMaxZ);
            $rightIntersectsViewport = $this->regionIntersectsViewport($rightRegionX, $rightRegionZ, $viewportMinX, $viewportMinZ, $viewportMaxX, $viewportMaxZ);

            if ($leftIntersectsViewport !== $rightIntersectsViewport) {
                return $leftIntersectsViewport ? -1 : 1;
            }

            $leftDistance = abs($leftRegionX - $focusRegionX) + abs($leftRegionZ - $focusRegionZ);
            $rightDistance = abs($rightRegionX - $focusRegionX) + abs($rightRegionZ - $focusRegionZ);

            if ($leftDistance !== $rightDistance) {
                return $leftDistance <=> $rightDistance;
            }

            return $left <=> $right;
        });

        return $regions;
    }

    private function worldToRegionCoordinate(int $worldCoordinate): int
    {
        return (int) floor($worldCoordinate / 512);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseRegionCoordinates(string $regionFile): array
    {
        if (preg_match('/^r\.(-?\d+)\.(-?\d+)\.mca$/', $regionFile, $matches) !== 1) {
            return [0, 0];
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function regionIntersectsViewport(
        int $regionX,
        int $regionZ,
        ?int $viewportMinX,
        ?int $viewportMinZ,
        ?int $viewportMaxX,
        ?int $viewportMaxZ
    ): bool {
        if (
            ! is_int($viewportMinX)
            || ! is_int($viewportMinZ)
            || ! is_int($viewportMaxX)
            || ! is_int($viewportMaxZ)
        ) {
            return false;
        }

        $regionMinX = $regionX * 512;
        $regionMinZ = $regionZ * 512;
        $regionMaxX = $regionMinX + 511;
        $regionMaxZ = $regionMinZ + 511;

        return ! (
            $regionMaxX < $viewportMinX
            || $regionMinX > $viewportMaxX
            || $regionMaxZ < $viewportMinZ
            || $regionMinZ > $viewportMaxZ
        );
    }
}

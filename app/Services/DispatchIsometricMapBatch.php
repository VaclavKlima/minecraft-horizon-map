<?php

namespace App\Services;

use App\Jobs\GenerateCombinedIsometricTilesJob;
use App\Jobs\RenderRegionIsometricImageJob;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

class DispatchIsometricMapBatch
{
    public function __construct(
        private Filesystem $files,
        private MinecraftRegionReader $minecraftRegionReader,
        private MinecraftIsometricRenderer $minecraftIsometricRenderer
    ) {}

    /**
     * @return array{batch_id:string,region_count:int,message:string}
     */
    public function dispatch(string $heightmapType = 'WORLD_SURFACE'): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to render map tiles.');
        }

        $regionFiles = $this->minecraftRegionReader->listRegionFiles();

        if ($regionFiles === []) {
            throw new RuntimeException('No region files found in public/region.');
        }

        $regionsToQueue = array_values(array_filter(
            $regionFiles,
            fn (string $regionFile): bool => $this->minecraftIsometricRenderer->regionNeedsRendering($regionFile, $heightmapType)
        ));

        if ($regionsToQueue === []) {
            if (! $this->combinedTilesAvailable()) {
                GenerateCombinedIsometricTilesJob::dispatch();

                return [
                    'batch_id' => '',
                    'region_count' => 0,
                    'message' => 'No changed regions detected. Queued combined isometric tile rebuild.',
                ];
            }

            return [
                'batch_id' => '',
                'region_count' => 0,
                'message' => 'No changed regions detected.',
            ];
        }

        $jobs = array_map(
            fn (string $regionFile): RenderRegionIsometricImageJob => new RenderRegionIsometricImageJob($regionFile, $heightmapType),
            $regionsToQueue
        );

        $batch = Bus::batch($jobs)
            ->name('Render Minecraft isometric map')
            ->allowFailures()
            ->dispatch();

        return [
            'batch_id' => $batch->id,
            'region_count' => count($regionsToQueue),
            'message' => 'Queued isometric map generation jobs.',
        ];
    }

    private function combinedTilesAvailable(): bool
    {
        return $this->files->exists(
            public_path('maps/isometric/tiles'.DIRECTORY_SEPARATOR.'all'.DIRECTORY_SEPARATOR.'manifest.json')
        );
    }
}

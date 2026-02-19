<?php

namespace App\Services;

use App\Jobs\GenerateRegionIsometricTilesJob;
use App\Jobs\RenderRegionIsometricImageJob;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

class DispatchIsometricMapBatch
{
    public function __construct(
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
            return [
                'batch_id' => '',
                'region_count' => 0,
                'message' => 'No changed regions detected.',
            ];
        }

        $jobs = array_map(fn (string $regionFile): array => [
            new RenderRegionIsometricImageJob($regionFile, $heightmapType),
            new GenerateRegionIsometricTilesJob($regionFile),
        ], $regionsToQueue);

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
}

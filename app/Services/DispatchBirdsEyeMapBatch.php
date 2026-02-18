<?php

namespace App\Services;

use App\Jobs\GenerateRegionTilesJob;
use App\Jobs\RenderRegionMapImageJob;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

class DispatchBirdsEyeMapBatch
{
    public function __construct(private MinecraftRegionReader $minecraftRegionReader) {}

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

        $jobs = array_map(fn (string $regionFile): array => [
            new RenderRegionMapImageJob($regionFile, $heightmapType),
            new GenerateRegionTilesJob($regionFile),
        ], $regionFiles);

        $batch = Bus::batch($jobs)
            ->name('Render Minecraft birds-eye map')
            ->allowFailures()
            ->dispatch();

        return [
            'batch_id' => $batch->id,
            'region_count' => count($regionFiles),
            'message' => 'Queued map generation jobs.',
        ];
    }
}

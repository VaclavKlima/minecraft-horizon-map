<?php

namespace App\Services;

use App\Jobs\GenerateCombinedMapTilesJob;
use App\Jobs\RenderRegionMapTilesJob;
use Illuminate\Bus\Batch;
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

        $jobs = array_map(
            fn (string $regionFile): RenderRegionMapTilesJob => new RenderRegionMapTilesJob($regionFile, $heightmapType),
            $regionFiles
        );

        $batch = Bus::batch($jobs)
            ->name('Render Minecraft birds-eye map')
            ->allowFailures()
            ->finally(static function (Batch $batch): void {
                GenerateCombinedMapTilesJob::dispatch();
            })
            ->dispatch();

        return [
            'batch_id' => $batch->id,
            'region_count' => count($regionFiles),
            'message' => 'Queued map generation jobs.',
        ];
    }
}

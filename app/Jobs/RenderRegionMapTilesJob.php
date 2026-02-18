<?php

namespace App\Jobs;

use App\Services\MinecraftBirdsEyeRenderer;
use App\Services\MinecraftMapTileService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RenderRegionMapTilesJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public string $regionFile, public string $heightmapType = 'WORLD_SURFACE') {}

    public function handle(MinecraftBirdsEyeRenderer $minecraftBirdsEyeRenderer, MinecraftMapTileService $minecraftMapTileService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $region = $minecraftBirdsEyeRenderer->renderRegion($this->regionFile, $this->heightmapType);

        if ($region === null) {
            return;
        }

        $minecraftMapTileService->rebuildRegionTiles($this->regionFile);
    }
}

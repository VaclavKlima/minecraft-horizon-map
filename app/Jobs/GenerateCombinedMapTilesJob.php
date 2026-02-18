<?php

namespace App\Jobs;

use App\Services\MinecraftMapTileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateCombinedMapTilesJob implements ShouldQueue
{
    use Queueable;

    public function handle(MinecraftMapTileService $minecraftMapTileService): void
    {
        $minecraftMapTileService->rebuildCombinedTiles();
    }
}

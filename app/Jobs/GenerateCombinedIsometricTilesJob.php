<?php

namespace App\Jobs;

use App\Services\MinecraftIsometricTileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateCombinedIsometricTilesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function handle(MinecraftIsometricTileService $minecraftIsometricTileService): void
    {
        $minecraftIsometricTileService->rebuildCombinedTiles();
    }
}

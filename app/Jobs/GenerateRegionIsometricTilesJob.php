<?php

namespace App\Jobs;

use App\Services\MinecraftIsometricTileService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateRegionIsometricTilesJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public function __construct(public string $regionFile) {}

    public function handle(MinecraftIsometricTileService $minecraftIsometricTileService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $minecraftIsometricTileService->rebuildRegionTiles($this->regionFile);
    }
}

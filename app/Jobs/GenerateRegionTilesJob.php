<?php

namespace App\Jobs;

use App\Services\MinecraftMapTileService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateRegionTilesJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public string $regionFile) {}

    public function handle(MinecraftMapTileService $minecraftMapTileService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $minecraftMapTileService->rebuildRegionTiles($this->regionFile);
    }
}

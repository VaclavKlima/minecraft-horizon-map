<?php

namespace App\Jobs;

use App\Services\MinecraftMapTileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateBirdsEyeMinimapJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public ?string $regionFile = null) {}

    public function handle(MinecraftMapTileService $minecraftMapTileService): void
    {
        $minecraftMapTileService->rebuildMinimap($this->regionFile);
    }
}

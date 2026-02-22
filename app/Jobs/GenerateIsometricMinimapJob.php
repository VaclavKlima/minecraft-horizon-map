<?php

namespace App\Jobs;

use App\Services\MinecraftIsometricTileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateIsometricMinimapJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public ?string $regionFile = null) {}

    public function handle(MinecraftIsometricTileService $minecraftIsometricTileService): void
    {
        $minecraftIsometricTileService->rebuildMinimap($this->regionFile);
    }
}

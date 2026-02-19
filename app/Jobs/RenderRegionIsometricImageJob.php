<?php

namespace App\Jobs;

use App\Services\MinecraftIsometricRenderer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RenderRegionIsometricImageJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public function __construct(public string $regionFile, public string $heightmapType = 'WORLD_SURFACE') {}

    public function handle(MinecraftIsometricRenderer $minecraftIsometricRenderer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $minecraftIsometricRenderer->renderRegion($this->regionFile, $this->heightmapType);
    }
}

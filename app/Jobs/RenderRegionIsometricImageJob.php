<?php

namespace App\Jobs;

use App\Services\MinecraftIsometricRenderer;
use App\Services\MinecraftIsometricTileService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RenderRegionIsometricImageJob implements ShouldQueue
{
    use Batchable, Queueable;

    private const COMBINED_REFRESH_COOLDOWN_SECONDS = 15;

    public int $tries = 1;

    public function __construct(public string $regionFile, public string $heightmapType = 'WORLD_SURFACE') {}

    public function handle(
        MinecraftIsometricRenderer $minecraftIsometricRenderer,
        MinecraftIsometricTileService $minecraftIsometricTileService
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $minecraftIsometricRenderer->renderRegion($this->regionFile, $this->heightmapType);
        $minecraftIsometricTileService->rebuildRegionTiles($this->regionFile);
        if ($this->shouldRefreshCombinedTiles()) {
            $minecraftIsometricTileService->rebuildCombinedTiles();
        }
    }

    private function shouldRefreshCombinedTiles(): bool
    {
        $lockPath = storage_path('app'.DIRECTORY_SEPARATOR.'isometric-combined-refresh.timestamp');
        $now = time();
        $lastRefreshAt = is_file($lockPath) ? (int) file_get_contents($lockPath) : 0;

        if (($now - $lastRefreshAt) < self::COMBINED_REFRESH_COOLDOWN_SECONDS) {
            return false;
        }

        if (! is_dir(dirname($lockPath))) {
            mkdir(dirname($lockPath), 0755, true);
        }

        file_put_contents($lockPath, (string) $now, LOCK_EX);

        return true;
    }
}

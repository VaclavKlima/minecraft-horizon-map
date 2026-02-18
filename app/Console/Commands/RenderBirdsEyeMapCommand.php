<?php

namespace App\Console\Commands;

use App\Services\DispatchBirdsEyeMapBatch;
use App\Services\DispatchIsometricMapBatch;
use App\Services\MinecraftBirdsEyeRenderer;
use Illuminate\Console\Command;
use RuntimeException;

class RenderBirdsEyeMapCommand extends Command
{
    protected $signature = 'map:render-birdeye {--heightmap=WORLD_SURFACE} {--chunk-x=} {--chunk-z=} {--isometric}';

    protected $description = 'Queue birds-eye map rendering and tile generation jobs from public/region';

    public function __construct(
        private MinecraftBirdsEyeRenderer $minecraftBirdsEyeRenderer,
        private DispatchBirdsEyeMapBatch $dispatchBirdsEyeMapBatch,
        private DispatchIsometricMapBatch $dispatchIsometricMapBatch
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunkX = $this->option('chunk-x');
        $chunkZ = $this->option('chunk-z');
        $isometric = $this->option('isometric') === true;

        if (($chunkX === null) xor ($chunkZ === null)) {
            $this->error('Provide both --chunk-x and --chunk-z, or neither.');

            return self::FAILURE;
        }

        try {
            if ($chunkX !== null && $chunkZ !== null) {
                if ($isometric) {
                    $this->error('Chunk rendering supports birds-eye mode only.');

                    return self::FAILURE;
                }

                $result = $this->minecraftBirdsEyeRenderer->renderChunk(
                    (int) $chunkX,
                    (int) $chunkZ,
                    (string) $this->option('heightmap'),
                );
                $this->info('Chunk map rendered.');
                $this->line('File: '.$result['relative_path']);
                $this->line('Chunk: '.$result['chunk_x'].', '.$result['chunk_z']);

                return self::SUCCESS;
            }

            $result = $isometric
                ? $this->dispatchIsometricMapBatch->dispatch((string) $this->option('heightmap'))
                : $this->dispatchBirdsEyeMapBatch->dispatch((string) $this->option('heightmap'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($isometric ? 'Queued isometric render jobs.' : 'Queued birds-eye render jobs.');
        $this->line('Batch ID: '.$result['batch_id']);
        $this->line('Regions queued: '.$result['region_count']);
        $this->line('Run a queue worker to process jobs: php artisan queue:work');

        return self::SUCCESS;
    }
}

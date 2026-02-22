<?php

namespace App\Console\Commands;

use App\Services\DispatchBirdsEyeMapBatch;
use App\Services\DispatchIsometricMapBatch;
use App\Services\MinecraftBirdsEyeRenderer;
use Illuminate\Console\Command;
use RuntimeException;

class RenderBirdsEyeMapCommand extends Command
{
    protected $signature = 'map:render {--heightmap=WORLD_SURFACE} {--chunk-x=} {--chunk-z=} {--projection=} {--isometric}';

    protected $description = 'Queue map rendering jobs from public/region';

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
        $chunkMode = $chunkX !== null && $chunkZ !== null;
        $isometricAlias = $this->option('isometric') === true;
        $projectionOption = strtolower(trim((string) ($this->option('projection') ?? '')));

        if (($chunkX === null) xor ($chunkZ === null)) {
            $this->error('Provide both --chunk-x and --chunk-z, or neither.');

            return self::FAILURE;
        }

        if ($projectionOption !== '' && ! in_array($projectionOption, ['birds-eye', 'isometric'], true)) {
            $this->error('Projection must be either "birds-eye" or "isometric".');

            return self::FAILURE;
        }

        if ($projectionOption !== '' && $isometricAlias) {
            $this->error('Use either --projection or --isometric, not both.');

            return self::FAILURE;
        }

        $projection = $projectionOption;

        if ($projection === '' && $isometricAlias) {
            $projection = 'isometric';
        }

        if ($projection === '' && $chunkMode) {
            $projection = 'birds-eye';
        }

        if ($projection === '' && $this->input->isInteractive()) {
            $projection = $this->choice(
                'What projection do you want to render?',
                ['birds-eye', 'isometric'],
                'birds-eye'
            );
        }

        if ($projection === '') {
            $projection = 'birds-eye';
        }

        try {
            if ($chunkMode) {
                if ($projection === 'isometric') {
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

            $result = $projection === 'isometric'
                ? $this->dispatchIsometricMapBatch->dispatch((string) $this->option('heightmap'))
                : $this->dispatchBirdsEyeMapBatch->dispatch((string) $this->option('heightmap'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($projection === 'isometric' ? 'Queued isometric render jobs.' : 'Queued birds-eye render jobs.');
        $this->line('Batch ID: '.$result['batch_id']);
        $this->line('Regions queued: '.$result['region_count']);
        $this->line('Run a queue worker to process jobs: php artisan queue:work');

        return self::SUCCESS;
    }
}

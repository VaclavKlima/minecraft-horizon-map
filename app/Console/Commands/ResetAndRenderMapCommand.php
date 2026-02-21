<?php

namespace App\Console\Commands;

use App\Services\DispatchBirdsEyeMapBatch;
use App\Services\DispatchIsometricMapBatch;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ResetAndRenderMapCommand extends Command
{
    protected $signature = 'map:reset-render {--heightmap=WORLD_SURFACE} {--isometric} {--queue=}';

    protected $description = 'Clear queue + generated maps, then dispatch fresh map render jobs';

    public function __construct(
        private Filesystem $files,
        private DispatchBirdsEyeMapBatch $dispatchBirdsEyeMapBatch,
        private DispatchIsometricMapBatch $dispatchIsometricMapBatch
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isometric = $this->option('isometric') === true;
        $heightmap = (string) $this->option('heightmap');
        $queueName = $this->option('queue');
        $connection = (string) config('queue.default', 'database');

        $this->components->info('Clearing queued jobs...');
        $clearedJobs = $this->clearQueuedJobs($connection, is_string($queueName) && $queueName !== '' ? $queueName : null);
        $this->line('Queued jobs removed: '.$clearedJobs);

        $this->components->info('Clearing failed jobs...');
        $this->clearFailedJobs();

        $this->components->info('Deleting generated map files...');
        $this->deleteGeneratedMapFiles($isometric);

        try {
            $result = $isometric
                ? $this->dispatchIsometricMapBatch->dispatch($heightmap)
                : $this->dispatchBirdsEyeMapBatch->dispatch($heightmap);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $mode = $isometric ? 'isometric' : 'birds-eye';
        $this->info('Queued fresh '.$mode.' map render jobs.');
        $this->line('Batch ID: '.$result['batch_id']);
        $this->line('Regions queued: '.$result['region_count']);
        $this->line('Run a worker to process jobs: php artisan queue:work');

        return self::SUCCESS;
    }

    private function clearQueuedJobs(string $connection, ?string $queueName): int
    {
        if ($connection === 'database') {
            $query = DB::table('jobs');

            if ($queueName !== null) {
                $query->where('queue', $queueName);
            }

            return $query->delete();
        }

        $params = ['connection' => $connection];

        if ($queueName !== null) {
            $params['--queue'] = $queueName;
        }

        try {
            $exitCode = $this->call('queue:clear', $params);

            if ($exitCode !== self::SUCCESS) {
                $this->warn('queue:clear returned a non-zero exit code.');
            }
        } catch (Throwable $throwable) {
            $this->warn('queue:clear failed: '.$throwable->getMessage());
        }

        return 0;
    }

    private function clearFailedJobs(): void
    {
        try {
            DB::table('failed_jobs')->delete();
        } catch (QueryException) {
            $this->warn('Unable to clear failed jobs table.');
        }
    }

    private function deleteGeneratedMapFiles(bool $isometric): void
    {
        if ($isometric) {
            $this->files->deleteDirectory(public_path('maps/isometric'));
            $this->files->delete(storage_path('app'.DIRECTORY_SEPARATOR.'isometric-combined-refresh.timestamp'));

            return;
        }

        $this->files->deleteDirectory(public_path('maps/regions'));
        $this->files->deleteDirectory(public_path('maps/tiles'));
    }
}

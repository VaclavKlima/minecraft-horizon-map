<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class BirdsEyeNativeRenderer
{
    public function __construct(private Filesystem $files) {}

    /**
     * @return array{
     *     region_file:string,
     *     file:string,
     *     relative_path:string,
     *     width_blocks:int,
     *     height_blocks:int,
     *     min_height:float,
     *     max_height:float,
     *     chunk_count:int
     * }|null
     */
    public function renderFromSectionsSnapshot(
        string $regionFile,
        string $heightmapType,
        string $sectionsPath,
        int $chunkCount,
        int $sourceWidth,
        int $sourceHeight
    ): ?array {
        if (! config('render.birds_eye_native_enabled', false)) {
            return null;
        }

        $binaryPath = $this->resolveBinaryPath((string) config('render.birds_eye_native_binary', ''));

        if ($binaryPath === '' || ! $this->files->exists($binaryPath)) {
            return null;
        }

        $outputRelativePath = 'maps/regions/'.str_replace('.mca', '.png', $regionFile);
        $outputPath = public_path($outputRelativePath);
        $this->files->ensureDirectoryExists(dirname($outputPath));

        try {
            $process = new Process([
                $binaryPath,
                '--projection',
                'birds-eye',
                '--output-path',
                $outputPath,
                '--source-width',
                (string) $sourceWidth,
                '--source-height',
                (string) $sourceHeight,
                '--pixel-scale',
                '1',
                '--sections-path',
                $sectionsPath,
            ]);
            $process->setTimeout((float) config('render.birds_eye_native_timeout_seconds', 300));
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning('Native birds-eye renderer failed.', [
                    'region_file' => $regionFile,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => trim($process->getErrorOutput()),
                    'stdout' => trim($process->getOutput()),
                ]);

                return null;
            }

            if (! $this->files->exists($outputPath)) {
                throw new RuntimeException('Native renderer succeeded but did not write an output file.');
            }

            /** @var array{min_height:float,max_height:float}|null $metrics */
            $metrics = $this->decodeMetrics($process->getOutput());
            $minHeight = $metrics['min_height'] ?? 0.0;
            $maxHeight = $metrics['max_height'] ?? 0.0;
        } catch (\Throwable $exception) {
            Log::warning('Native birds-eye renderer threw an exception.', [
                'region_file' => $regionFile,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return [
            'region_file' => $regionFile,
            'file' => basename($outputPath),
            'relative_path' => $outputRelativePath,
            'width_blocks' => $sourceWidth,
            'height_blocks' => $sourceHeight,
            'min_height' => $minHeight,
            'max_height' => $maxHeight,
            'chunk_count' => $chunkCount,
        ];
    }

    /**
     * @return array{min_height:float,max_height:float}|null
     */
    private function decodeMetrics(string $stdout): ?array
    {
        $decoded = json_decode(trim($stdout), true);

        if (! is_array($decoded)) {
            return null;
        }

        if (! isset($decoded['min_height'], $decoded['max_height'])) {
            return null;
        }

        return [
            'min_height' => (float) $decoded['min_height'],
            'max_height' => (float) $decoded['max_height'],
        ];
    }

    private function resolveBinaryPath(string $binaryPath): string
    {
        $trimmed = trim($binaryPath);

        if ($trimmed === '') {
            return '';
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);

        if ($this->isAbsolutePath($normalized)) {
            return $normalized;
        }

        return base_path($normalized);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }
}

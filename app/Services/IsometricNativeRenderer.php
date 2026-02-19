<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class IsometricNativeRenderer
{
    public function __construct(private Filesystem $files) {}

    /**
     * @param  array<int, array{chunk_x:int,chunk_z:int,sections:array<int, array<string, mixed>>}>  $chunks
     * @return array{
     *     region_file:string,
     *     file:string,
     *     relative_path:string,
     *     width_blocks:int,
     *     height_blocks:int
     * }|null
     */
    public function renderFromSections(
        string $regionFile,
        string $heightmapType,
        array $chunks,
        int $sourceWidth,
        int $sourceHeight
    ): ?array {
        if (! config('render.isometric_native_enabled', false)) {
            return null;
        }

        $binaryPath = (string) config('render.isometric_native_binary', '');

        if ($binaryPath === '' || ! $this->files->exists($binaryPath)) {
            return null;
        }

        $outputRelativePath = 'maps/isometric/regions/'.str_replace('.mca', '.png', $regionFile);
        $outputPath = public_path($outputRelativePath);
        $this->files->ensureDirectoryExists(dirname($outputPath));

        $tempDir = storage_path('app'.DIRECTORY_SEPARATOR.'isometric-native'.DIRECTORY_SEPARATOR.uniqid('run_', true));
        $this->files->ensureDirectoryExists($tempDir);
        $sectionsPath = $tempDir.DIRECTORY_SEPARATOR.'sections.json';

        try {
            $sectionsPayload = [
                'region_file' => $regionFile,
                'heightmap_type' => $heightmapType,
                'source_width' => $sourceWidth,
                'source_height' => $sourceHeight,
                'chunks' => $chunks,
            ];
            $this->files->put($sectionsPath, json_encode($sectionsPayload, JSON_THROW_ON_ERROR));

            $process = new Process([
                $binaryPath,
                '--output-path',
                $outputPath,
                '--source-width',
                (string) $sourceWidth,
                '--source-height',
                (string) $sourceHeight,
                '--sections-path',
                $sectionsPath,
            ]);
            $process->setTimeout((float) config('render.isometric_native_timeout_seconds', 300));
            $process->run();

            if (! $process->isSuccessful()) {
                if ($process->getExitCode() === 3) {
                    return null;
                }

                Log::warning('Native isometric renderer failed.', [
                    'region_file' => $regionFile,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => trim($process->getErrorOutput()),
                ]);

                return null;
            }

            if (! $this->files->exists($outputPath)) {
                throw new RuntimeException('Native renderer succeeded but did not write an output file.');
            }

            /** @var array{iso_width:int,iso_height:int}|null $metrics */
            $metrics = $this->decodeMetrics($process->getOutput());
            $isoWidth = $metrics['iso_width'] ?? ($sourceWidth + $sourceHeight);
            $isoHeight = $metrics['iso_height'] ?? (int) ceil(($sourceWidth + $sourceHeight) / 2);
        } catch (\Throwable $exception) {
            Log::warning('Native isometric renderer threw an exception.', [
                'region_file' => $regionFile,
                'error' => $exception->getMessage(),
            ]);

            return null;
        } finally {
            $this->files->deleteDirectory($tempDir);
        }

        return [
            'region_file' => $regionFile,
            'file' => basename($outputPath),
            'relative_path' => $outputRelativePath,
            'width_blocks' => $isoWidth,
            'height_blocks' => $isoHeight,
        ];
    }

    /**
     * @return array{iso_width:int,iso_height:int}|null
     */
    private function decodeMetrics(string $stdout): ?array
    {
        $decoded = json_decode(trim($stdout), true);

        if (! is_array($decoded)) {
            return null;
        }

        if (! isset($decoded['iso_width'], $decoded['iso_height'])) {
            return null;
        }

        return [
            'iso_width' => (int) $decoded['iso_width'],
            'iso_height' => (int) $decoded['iso_height'],
        ];
    }
}

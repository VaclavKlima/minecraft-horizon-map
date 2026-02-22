<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class IsometricNativeRenderer
{
    private const MISSING_BLOCKS_LOG = 'logs/isometric-missing-blocks.log';

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
        $pixelScale = max(1, (int) config('render.isometric_native_pixel_scale', 1));

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
                '--pixel-scale',
                (string) $pixelScale,
                '--sections-path',
                $sectionsPath,
            ]);
            $process->setTimeout((float) config('render.isometric_native_timeout_seconds', 300));
            $process->run();
            $this->appendMissingPaletteColorLogs($regionFile, $process->getErrorOutput());

            if (! $process->isSuccessful()) {
                if ($process->getExitCode() === 3) {
                    Log::info('Native isometric renderer reported empty region; generating transparent placeholder.', [
                        'region_file' => $regionFile,
                    ]);

                    [$isoWidth, $isoHeight] = $this->createTransparentPlaceholderRegionImage(
                        $outputPath,
                        $sourceWidth,
                        $sourceHeight,
                        $pixelScale
                    );

                    return [
                        'region_file' => $regionFile,
                        'file' => basename($outputPath),
                        'relative_path' => $outputRelativePath,
                        'width_blocks' => $isoWidth,
                        'height_blocks' => $isoHeight,
                    ];
                }

                Log::warning('Native isometric renderer failed.', [
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

            /** @var array{iso_width:int,iso_height:int}|null $metrics */
            $metrics = $this->decodeMetrics($process->getOutput());
            $isoWidth = $metrics['iso_width'] ?? (($sourceWidth + $sourceHeight + 2) * $pixelScale);
            $isoHeight = $metrics['iso_height'] ?? ((int) ceil(($sourceWidth + $sourceHeight) / 2) * $pixelScale);
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

    private function appendMissingPaletteColorLogs(string $regionFile, string $stderr): void
    {
        $trimmed = trim($stderr);

        if ($trimmed === '') {
            return;
        }

        $lines = preg_split('/\R/u', $trimmed) ?: [];
        $missingLines = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (
                str_starts_with($line, 'missing palette color:')
                || str_starts_with($line, 'encountered ')
            ) {
                $missingLines[] = $line;
            }
        }

        if ($missingLines === []) {
            return;
        }

        $logPath = storage_path(self::MISSING_BLOCKS_LOG);
        $this->files->ensureDirectoryExists(dirname($logPath));
        $timestamp = now()->format('Y-m-d H:i:s');

        foreach ($missingLines as $missingLine) {
            $this->files->append(
                $logPath,
                sprintf('[%s] region=%s %s%s', $timestamp, $regionFile, $missingLine, PHP_EOL)
            );
        }
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

    /**
     * @return array{0:int,1:int}
     */
    private function createTransparentPlaceholderRegionImage(
        string $outputPath,
        int $sourceWidth,
        int $sourceHeight,
        int $pixelScale
    ): array {
        $safeScale = max(1, $pixelScale);
        $isoWidth = ($sourceWidth + $sourceHeight + 2) * $safeScale;
        $isoHeight = ((int) ceil(($sourceWidth + $sourceHeight) / 2) + 8) * $safeScale;
        $placeholder = imagecreatetruecolor($isoWidth, $isoHeight);

        if ($placeholder === false) {
            throw new RuntimeException('Unable to allocate transparent placeholder image.');
        }

        imagealphablending($placeholder, false);
        imagesavealpha($placeholder, true);
        $transparent = imagecolorallocatealpha($placeholder, 0, 0, 0, 127);
        imagefill($placeholder, 0, 0, $transparent);
        imagepng($placeholder, $outputPath);
        imagedestroy($placeholder);

        return [$isoWidth, $isoHeight];
    }
}

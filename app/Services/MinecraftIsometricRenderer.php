<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class MinecraftIsometricRenderer
{
    private const RENDER_METADATA_VERSION = 3;

    private const DEPTH_SCALE = 0.6;

    private const HEIGHT_BASELINE = -128;

    private const HEIGHT_CEILING = 384;

    public function __construct(private Filesystem $files) {}

    /**
     * @return array{
     *     region_file:string,
     *     file:string,
     *     relative_path:string,
     *     width_blocks:int,
     *     height_blocks:int
     * }|null
     */
    public function renderRegion(string $regionFile): ?array
    {
        $sourcePath = $this->sourceMapPath($regionFile);

        if (! $this->files->exists($sourcePath)) {
            return null;
        }

        $sourceImage = imagecreatefrompng($sourcePath);

        if ($sourceImage === false) {
            throw new RuntimeException('Unable to open source birds-eye map image for isometric rendering.');
        }

        $heightMapPath = $this->heightMapPath($regionFile);
        $heightImage = $this->files->exists($heightMapPath) ? imagecreatefrompng($heightMapPath) : false;

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        $heightSpan = self::HEIGHT_CEILING - self::HEIGHT_BASELINE;
        $verticalDepthPadding = (int) ceil($heightSpan * self::DEPTH_SCALE);
        $isoWidth = $sourceWidth + $sourceHeight;
        $isoHeight = (int) ceil(($sourceWidth + $sourceHeight) / 2) + $verticalDepthPadding + 2;
        $isometricImage = imagecreatetruecolor($isoWidth, $isoHeight);

        if ($isometricImage === false) {
            imagedestroy($sourceImage);
            throw new RuntimeException('Unable to allocate isometric output image.');
        }

        imagealphablending($isometricImage, false);
        imagesavealpha($isometricImage, true);
        $transparent = imagecolorallocatealpha($isometricImage, 0, 0, 0, 127);
        imagefill($isometricImage, 0, 0, $transparent);

        $colorCache = [];

        for ($sourceY = 0; $sourceY < $sourceHeight; $sourceY++) {
            for ($sourceX = 0; $sourceX < $sourceWidth; $sourceX++) {
                $sourceColor = imagecolorat($sourceImage, $sourceX, $sourceY);
                $rgb = imagecolorsforindex($sourceImage, $sourceColor);
                $r = (int) $rgb['red'];
                $g = (int) $rgb['green'];
                $b = (int) $rgb['blue'];
                $a = (int) $rgb['alpha'];
                $cacheKey = ($a << 24) | ($r << 16) | ($g << 8) | $b;

                if (! array_key_exists($cacheKey, $colorCache)) {
                    $colorCache[$cacheKey] = imagecolorallocatealpha($isometricImage, $r, $g, $b, $a);
                }

                $surfaceHeight = $heightImage !== false
                    ? $this->decodeHeightAt($heightImage, $sourceX, $sourceY)
                    : self::HEIGHT_BASELINE;
                $clampedHeight = max(self::HEIGHT_BASELINE, min(self::HEIGHT_CEILING, $surfaceHeight));
                $depthOffset = (int) round(($clampedHeight - self::HEIGHT_BASELINE) * self::DEPTH_SCALE);
                $isoX = ($sourceX - $sourceY) + ($sourceHeight - 1);
                $isoY = (int) floor(($sourceX + $sourceY) / 2) + $verticalDepthPadding - $depthOffset;
                imagesetpixel($isometricImage, $isoX, $isoY, $colorCache[$cacheKey]);
            }
        }

        $outputRelativePath = 'maps/isometric/regions/'.str_replace('.mca', '.png', $regionFile);
        $outputPath = public_path($outputRelativePath);
        $this->files->ensureDirectoryExists(dirname($outputPath));
        imagepng($isometricImage, $outputPath);
        imagedestroy($isometricImage);
        imagedestroy($sourceImage);

        if ($heightImage !== false) {
            imagedestroy($heightImage);
        }

        $metadata = [
            'version' => self::RENDER_METADATA_VERSION,
            'source_modified_at' => $this->files->lastModified($sourcePath),
            'rendered_at' => time(),
        ];
        $metadataPath = $this->renderMetadataPath($regionFile);
        $this->files->ensureDirectoryExists(dirname($metadataPath));
        $this->files->put($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return [
            'region_file' => $regionFile,
            'file' => basename($outputPath),
            'relative_path' => $outputRelativePath,
            'width_blocks' => $isoWidth,
            'height_blocks' => $isoHeight,
        ];
    }

    public function regionNeedsRendering(string $regionFile): bool
    {
        $sourcePath = $this->sourceMapPath($regionFile);
        $renderPath = $this->isometricRegionPath($regionFile);
        $metadataPath = $this->renderMetadataPath($regionFile);
        $heightMapPath = $this->heightMapPath($regionFile);

        if (! $this->files->exists($sourcePath) || ! $this->files->exists($renderPath) || ! $this->files->exists($metadataPath) || ! $this->files->exists($heightMapPath)) {
            return true;
        }

        try {
            /** @var array<string, mixed> $metadata */
            $metadata = json_decode($this->files->get($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return true;
        }

        return ($metadata['version'] ?? null) !== self::RENDER_METADATA_VERSION
            || ($metadata['source_modified_at'] ?? null) !== $this->files->lastModified($sourcePath);
    }

    private function sourceMapPath(string $regionFile): string
    {
        return public_path('maps/regions'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }

    private function isometricRegionPath(string $regionFile): string
    {
        return public_path('maps/isometric/regions'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }

    private function renderMetadataPath(string $regionFile): string
    {
        return public_path('maps/isometric/regions'.DIRECTORY_SEPARATOR.'.meta'.DIRECTORY_SEPARATOR.str_replace('.mca', '.json', $regionFile));
    }

    private function heightMapPath(string $regionFile): string
    {
        return public_path('maps/regions'.DIRECTORY_SEPARATOR.'.heights'.DIRECTORY_SEPARATOR.str_replace('.mca', '.png', $regionFile));
    }

    private function decodeHeightAt(\GdImage $heightImage, int $x, int $y): int
    {
        $encodedColor = imagecolorat($heightImage, $x, $y);
        $red = ($encodedColor >> 16) & 0xFF;
        $green = ($encodedColor >> 8) & 0xFF;
        $encodedHeight = ($red << 8) | $green;

        return $encodedHeight - 32768;
    }
}

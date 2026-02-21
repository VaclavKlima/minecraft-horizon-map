<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

class MinecraftRegionReader
{
    public function __construct(private Filesystem $files) {}

    /**
     * @return array<int, string>
     */
    public function listRegionFiles(): array
    {
        $directory = $this->regionDirectory();

        if (! $this->files->isDirectory($directory)) {
            return [];
        }

        $regionFiles = [];

        foreach ($this->files->files($directory) as $file) {
            if (strtolower($file->getExtension()) === 'mca') {
                $regionFiles[] = $file->getFilename();
            }
        }

        sort($regionFiles);

        return $regionFiles;
    }

    /**
     * @return array{
     *     file: string,
     *     region: array{x:int, z:int},
     *     chunks_total: int,
     *     chunks_returned: int,
     *     chunks: array<int, array<string, mixed>>
     * }
     */
    public function readRegionFile(string $fileName, int $limit = 20, bool $includeNbt = false): array
    {
        $normalizedFileName = basename($fileName);

        if (! preg_match('/^r\.-?\d+\.-?\d+\.mca$/', $normalizedFileName)) {
            throw new InvalidArgumentException('Invalid region file name.');
        }

        $path = $this->regionDirectory().DIRECTORY_SEPARATOR.$normalizedFileName;

        if (! $this->files->exists($path)) {
            throw new InvalidArgumentException('Region file not found.');
        }

        $binary = $this->files->get($path);

        if (strlen($binary) < 8192) {
            throw new RuntimeException('Invalid region file header.');
        }

        [$regionX, $regionZ] = $this->parseRegionCoordinates($normalizedFileName);

        $chunks = [];
        $totalChunks = 0;
        $locationHeader = substr($binary, 0, 4096);
        $timestampHeader = substr($binary, 4096, 4096);
        $binaryLength = strlen($binary);
        $safeLimit = max(1, min(1024, $limit));

        for ($index = 0; $index < 1024; $index++) {
            $locationEntry = substr($locationHeader, $index * 4, 4);
            $offset = unpack('N', "\x00".substr($locationEntry, 0, 3))[1];
            $sectorCount = ord($locationEntry[3]);

            if ($offset === 0 || $sectorCount === 0) {
                continue;
            }

            $totalChunks++;

            if (count($chunks) >= $safeLimit) {
                continue;
            }

            $localChunkX = $index % 32;
            $localChunkZ = intdiv($index, 32);
            $worldChunkX = ($regionX * 32) + $localChunkX;
            $worldChunkZ = ($regionZ * 32) + $localChunkZ;
            $timestamp = unpack('N', substr($timestampHeader, $index * 4, 4))[1];
            $byteOffset = $offset * 4096;

            $chunkData = [
                'index' => $index,
                'local_chunk' => ['x' => $localChunkX, 'z' => $localChunkZ],
                'world_chunk' => ['x' => $worldChunkX, 'z' => $worldChunkZ],
                'offset_sectors' => $offset,
                'sector_count' => $sectorCount,
                'timestamp' => $timestamp,
                'compression' => null,
                'chunk_length' => null,
            ];

            if ($byteOffset + 5 > $binaryLength) {
                $chunkData['error'] = 'Chunk offset points outside the region file.';
                $chunks[] = $chunkData;

                continue;
            }

            $storedLength = unpack('N', substr($binary, $byteOffset, 4))[1];
            $compressionType = ord($binary[$byteOffset + 4]);
            $compressed = substr($binary, $byteOffset + 5, max(0, $storedLength - 1));
            $decompressed = $this->decompressChunk($compressed, $compressionType);

            $chunkData['compression'] = $this->compressionName($compressionType);
            $chunkData['chunk_length'] = $storedLength;

            if ($decompressed === null) {
                $chunkData['error'] = 'Unsupported compression type or decompression failure.';
                $chunks[] = $chunkData;

                continue;
            }

            if ($includeNbt) {
                try {
                    $chunkData['nbt'] = $this->parseNbtRoot($decompressed);
                } catch (\Throwable $throwable) {
                    $chunkData['error'] = 'NBT parsing failed: '.$throwable->getMessage();
                }
            }

            $chunks[] = $chunkData;
        }

        return [
            'file' => $normalizedFileName,
            'region' => ['x' => $regionX, 'z' => $regionZ],
            'chunks_total' => $totalChunks,
            'chunks_returned' => count($chunks),
            'chunks' => $chunks,
        ];
    }

    private function regionDirectory(): string
    {
        return public_path('region');
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function parseRegionCoordinates(string $fileName): array
    {
        preg_match('/^r\.(-?\d+)\.(-?\d+)\.mca$/', $fileName, $matches);

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function compressionName(int $type): string
    {
        return match ($type) {
            1 => 'gzip',
            2 => 'zlib',
            3 => 'none',
            default => 'unknown',
        };
    }

    private function decompressChunk(string $data, int $compressionType): ?string
    {
        if ($compressionType === 1) {
            $decoded = gzdecode($data);

            return is_string($decoded) ? $decoded : null;
        }

        if ($compressionType === 2) {
            $decoded = zlib_decode($data);

            return is_string($decoded) ? $decoded : null;
        }

        if ($compressionType === 3) {
            return $data;
        }

        return null;
    }

    /**
     * @return array{name:string, data:array<string, mixed>}
     */
    private function parseNbtRoot(string $binary): array
    {
        $cursor = 0;
        $rootTagType = $this->readUnsignedByte($binary, $cursor);

        if ($rootTagType !== 10) {
            throw new RuntimeException('Root tag is not TAG_Compound.');
        }

        $rootName = $this->readString($binary, $cursor);
        $payload = $this->readTagPayload($rootTagType, $binary, $cursor);

        if (! is_array($payload)) {
            throw new RuntimeException('Unexpected NBT root payload.');
        }

        return [
            'name' => $rootName,
            'data' => $payload,
        ];
    }

    private function readTagPayload(int $type, string $binary, int &$cursor): mixed
    {
        return match ($type) {
            0 => null,
            1 => $this->readSignedByte($binary, $cursor),
            2 => $this->readSignedShort($binary, $cursor),
            3 => $this->readSignedInt($binary, $cursor),
            4 => $this->readLongHex($binary, $cursor),
            5 => $this->readFloat($binary, $cursor),
            6 => $this->readDouble($binary, $cursor),
            7 => $this->readByteArray($binary, $cursor),
            8 => $this->readString($binary, $cursor),
            9 => $this->readList($binary, $cursor),
            10 => $this->readCompound($binary, $cursor),
            11 => $this->readIntArray($binary, $cursor),
            12 => $this->readLongArray($binary, $cursor),
            default => throw new RuntimeException("Unsupported NBT tag type {$type}."),
        };
    }

    private function readSignedByte(string $binary, int &$cursor): int
    {
        $value = $this->readUnsignedByte($binary, $cursor);

        if ($value >= 0x80) {
            return $value - 0x100;
        }

        return $value;
    }

    private function readUnsignedByte(string $binary, int &$cursor): int
    {
        if (! isset($binary[$cursor])) {
            throw new RuntimeException('Unexpected end of NBT data while reading byte.');
        }

        $value = ord($binary[$cursor]);
        $cursor++;

        return $value;
    }

    private function readSignedShort(string $binary, int &$cursor): int
    {
        $raw = unpack('n', $this->readBytes($binary, $cursor, 2))[1];

        if ($raw >= 0x8000) {
            return $raw - 0x10000;
        }

        return $raw;
    }

    private function readSignedInt(string $binary, int &$cursor): int
    {
        $raw = unpack('N', $this->readBytes($binary, $cursor, 4))[1];

        if ($raw >= 0x80000000) {
            return $raw - 0x100000000;
        }

        return $raw;
    }

    private function readLongHex(string $binary, int &$cursor): string
    {
        return '0x'.bin2hex($this->readBytes($binary, $cursor, 8));
    }

    private function readFloat(string $binary, int &$cursor): float
    {
        return unpack('G', $this->readBytes($binary, $cursor, 4))[1];
    }

    private function readDouble(string $binary, int &$cursor): float
    {
        return unpack('E', $this->readBytes($binary, $cursor, 8))[1];
    }

    /**
     * @return array<int, int>
     */
    private function readByteArray(string $binary, int &$cursor): array
    {
        $length = $this->readSignedInt($binary, $cursor);
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $values[] = $this->readSignedByte($binary, $cursor);
        }

        return $values;
    }

    private function readString(string $binary, int &$cursor): string
    {
        $length = unpack('n', $this->readBytes($binary, $cursor, 2))[1];

        return $this->readBytes($binary, $cursor, $length);
    }

    /**
     * @return array<int, mixed>
     */
    private function readList(string $binary, int &$cursor): array
    {
        $itemType = $this->readUnsignedByte($binary, $cursor);
        $length = $this->readSignedInt($binary, $cursor);
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $values[] = $this->readTagPayload($itemType, $binary, $cursor);
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function readCompound(string $binary, int &$cursor): array
    {
        $values = [];

        while (true) {
            $tagType = $this->readUnsignedByte($binary, $cursor);

            if ($tagType === 0) {
                break;
            }

            $name = $this->readString($binary, $cursor);
            $values[$name] = $this->readTagPayload($tagType, $binary, $cursor);
        }

        return $values;
    }

    /**
     * @return array<int, int>
     */
    private function readIntArray(string $binary, int &$cursor): array
    {
        $length = $this->readSignedInt($binary, $cursor);
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $values[] = $this->readSignedInt($binary, $cursor);
        }

        return $values;
    }

    /**
     * @return array<int, string>
     */
    private function readLongArray(string $binary, int &$cursor): array
    {
        $length = $this->readSignedInt($binary, $cursor);
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $values[] = $this->readLongHex($binary, $cursor);
        }

        return $values;
    }

    private function readBytes(string $binary, int &$cursor, int $length): string
    {
        $chunk = substr($binary, $cursor, $length);

        if (strlen($chunk) !== $length) {
            throw new RuntimeException('Unexpected end of NBT data.');
        }

        $cursor += $length;

        return $chunk;
    }
}

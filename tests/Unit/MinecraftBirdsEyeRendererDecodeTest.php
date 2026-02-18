<?php

use App\Services\MinecraftBirdsEyeRenderer;

it('decodes modern padded block state values for non-divisor bit widths', function () {
    $renderer = app(MinecraftBirdsEyeRenderer::class);
    $method = new ReflectionMethod($renderer, 'decodePackedValues');
    $method->setAccessible(true);
    $bitsPerEntry = 5;
    $values = [];

    for ($index = 0; $index < 4096; $index++) {
        $values[] = $index % 20;
    }

    $packedLongBytes = packPaddedLongBytes($values, $bitsPerEntry);
    /** @var array<int, int> $decoded */
    $decoded = $method->invoke($renderer, $packedLongBytes, 4096, $bitsPerEntry);

    expect($decoded)->toHaveCount(4096);
    expect(array_slice($decoded, 0, 256))->toBe(array_slice($values, 0, 256));
    expect($decoded[4095])->toBe($values[4095]);
});

/**
 * @param  array<int, int>  $values
 * @return array<int, string>
 */
function packPaddedLongBytes(array $values, int $bitsPerEntry): array
{
    $valuesPerLong = intdiv(64, $bitsPerEntry);
    $longCount = (int) ceil(count($values) / $valuesPerLong);
    $longBytes = [];

    for ($longIndex = 0; $longIndex < $longCount; $longIndex++) {
        $longByteValues = array_fill(0, 8, 0);

        for ($valueIndex = 0; $valueIndex < $valuesPerLong; $valueIndex++) {
            $entryIndex = ($longIndex * $valuesPerLong) + $valueIndex;

            if (! array_key_exists($entryIndex, $values)) {
                break;
            }

            $value = $values[$entryIndex];

            for ($bitOffset = 0; $bitOffset < $bitsPerEntry; $bitOffset++) {
                $bit = ($value >> $bitOffset) & 1;
                $bitIndex = ($valueIndex * $bitsPerEntry) + $bitOffset;
                $byteIndexFromLsb = intdiv($bitIndex, 8);
                $bitInByte = $bitIndex % 8;
                $bytePosition = 7 - $byteIndexFromLsb;
                $longByteValues[$bytePosition] |= ($bit << $bitInByte);
            }
        }

        $longBytes[] = pack(
            'C8',
            $longByteValues[0],
            $longByteValues[1],
            $longByteValues[2],
            $longByteValues[3],
            $longByteValues[4],
            $longByteValues[5],
            $longByteValues[6],
            $longByteValues[7]
        );
    }

    return $longBytes;
}

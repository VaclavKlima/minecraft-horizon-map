<?php

use App\Jobs\RenderRegionMapTilesJob;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('GD extension is not available.');
    }
});

it('queues birds-eye map jobs through the api', function () {
    Bus::fake();

    $response = $this->postJson('/api/maps/birdeye/render', [
        'heightmap' => 'WORLD_SURFACE',
    ]);

    $response->assertAccepted()
        ->assertJson(fn (\Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->whereType('batch_id', 'string')
            ->whereType('region_count', 'integer')
            ->where('region_count', fn (int $value) => $value > 0)
            ->etc())
        ->assertJsonPath('message', 'Queued map generation jobs.');

    Bus::assertBatched(function ($batch): bool {
        return count($batch->jobs) > 0
            && collect($batch->jobs)->every(fn (object $job): bool => $job instanceof RenderRegionMapTilesJob);
    });
});

it('queues birds-eye map jobs through the command', function () {
    Bus::fake();

    $this->artisan('map:render-birdeye --heightmap=WORLD_SURFACE')
        ->assertSuccessful();

    Bus::assertBatched(function ($batch): bool {
        return count($batch->jobs) > 0
            && collect($batch->jobs)->every(fn (object $job): bool => $job instanceof RenderRegionMapTilesJob);
    });
});

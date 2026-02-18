<?php

use App\Jobs\GenerateRegionTilesJob;
use App\Jobs\RenderRegionMapImageJob;
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

    $payload = $response->assertAccepted()
        ->assertJson(fn (\Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->whereType('batch_id', 'string')
            ->whereType('region_count', 'integer')
            ->etc())
        ->json();

    if (($payload['region_count'] ?? 0) > 0) {
        $response->assertJsonPath('message', 'Queued map generation jobs.');

        Bus::assertBatched(function ($batch): bool {
            return count($batch->jobs) > 0
                && collect($batch->jobs)->every(
                    fn (mixed $jobs): bool => is_array($jobs)
                        && count($jobs) === 2
                        && $jobs[0] instanceof RenderRegionMapImageJob
                        && $jobs[1] instanceof GenerateRegionTilesJob
                );
        });

        return;
    }

    $response->assertJsonPath('message', 'No changed regions detected.');
    Bus::assertNothingBatched();
});

it('queues birds-eye map jobs through the command', function () {
    Bus::fake();

    $this->artisan('map:render-birdeye --heightmap=WORLD_SURFACE')
        ->assertSuccessful();

    $matchingBatches = Bus::batched(function ($batch): bool {
        return count($batch->jobs) > 0
            && collect($batch->jobs)->every(
                fn (mixed $jobs): bool => is_array($jobs)
                    && count($jobs) === 2
                    && $jobs[0] instanceof RenderRegionMapImageJob
                    && $jobs[1] instanceof GenerateRegionTilesJob
            );
    });

    if ($matchingBatches->count() === 0) {
        Bus::assertNothingBatched();

        return;
    }

    expect($matchingBatches->count())->toBeGreaterThan(0);
});

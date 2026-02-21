<?php

use App\Jobs\RenderRegionIsometricImageJob;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    if (! Schema::hasTable('job_batches')) {
        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }
});

it('queues isometric map jobs through the api', function () {
    Bus::fake();

    $response = $this->postJson('/api/maps/isometric/render', [
        'heightmap' => 'WORLD_SURFACE',
    ]);

    $payload = $response->assertAccepted()
        ->assertJson(fn (\Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->whereType('batch_id', 'string')
            ->whereType('region_count', 'integer')
            ->etc())
        ->json();

    if (($payload['region_count'] ?? 0) > 0) {
        $response->assertJsonPath('message', 'Queued isometric map generation jobs.');

        Bus::assertBatched(function ($batch): bool {
            return count($batch->jobs) > 0
                && collect($batch->jobs)->every(
                    fn (mixed $job): bool => $job instanceof RenderRegionIsometricImageJob
                );
        });

        return;
    }

    $response->assertJsonPath('message', 'No changed regions detected.');
    Bus::assertNothingBatched();
});

it('returns queue batch status for an existing batch id', function () {
    $batch = Bus::batch([
        new TestBatchedNoOpJob,
    ])->name('isometric status test')->dispatch();

    $response = $this->getJson('/api/maps/batches/'.$batch->id);

    $response->assertSuccessful()
        ->assertJsonPath('id', $batch->id)
        ->assertJsonStructure([
            'id',
            'name',
            'total_jobs',
            'pending_jobs',
            'processed_jobs',
            'failed_jobs',
            'progress',
            'finished',
            'cancelled',
        ]);
});

it('returns not found for an unknown batch id', function () {
    $response = $this->getJson('/api/maps/batches/40f91280-0f57-4f71-90e7-9414fb4cce00');

    $response->assertNotFound()
        ->assertJsonPath('message', 'Batch not found.');
});

class TestBatchedNoOpJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function handle(): void {}
}

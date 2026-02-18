<?php

it('lists available region files', function () {
    $response = $this->getJson('/api/regions');

    $response->assertSuccessful();
    expect($response->json('files'))->toContain('r.0.0.mca');
});

it('reads region chunk metadata', function () {
    $response = $this->getJson('/api/regions/r.0.0.mca?limit=2&include_nbt=0');

    $response->assertSuccessful()
        ->assertJsonPath('file', 'r.0.0.mca')
        ->assertJsonPath('region.x', 0)
        ->assertJsonPath('region.z', 0);

    expect($response->json('chunks_returned'))->toBeLessThanOrEqual(2);
});

it('returns not found for missing region file', function () {
    $response = $this->getJson('/api/regions/r.999.999.mca');

    $response->assertNotFound();
});

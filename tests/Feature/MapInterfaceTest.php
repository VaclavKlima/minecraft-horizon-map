<?php

use Illuminate\Support\Facades\File;

const REAL_REGION_MAP_PATH = 'maps/regions/r.0.0.png';
const REAL_REGION_MAP_BACKUP_PATH = 'maps/regions/r.0.0.test-backup.png';

beforeEach(function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    if (File::exists(public_path(REAL_REGION_MAP_PATH))) {
        File::copy(public_path(REAL_REGION_MAP_PATH), public_path(REAL_REGION_MAP_BACKUP_PATH));
    }
});

afterEach(function (): void {
    if (File::exists(public_path(REAL_REGION_MAP_BACKUP_PATH))) {
        File::move(public_path(REAL_REGION_MAP_BACKUP_PATH), public_path(REAL_REGION_MAP_PATH));
    }
});

it('loads the interactive map page', function () {
    $response = $this->get('/map');

    $response->assertSuccessful()
        ->assertSee('Minecraft Horizon Map');
});

it('returns map manifest with birds-eye image layers', function () {
    seedFakeBirdsEyeMap();

    $manifestResponse = $this->getJson('/api/maps/manifest?region=r.0.0.mca');
    $manifestResponse->assertSuccessful()
        ->assertJsonPath('available', true)
        ->assertJsonPath('manifest.projection', 'birds-eye')
        ->assertJsonPath('manifest.selected_region', 'r.0.0.mca')
        ->assertJsonPath('manifest.image_layers.0.file', 'r.0.0.mca')
        ->assertJsonPath('manifest.image_layers.0.offset_x', 0)
        ->assertJsonPath('manifest.image_layers.0.offset_y', 0)
        ->assertJsonPath('manifest.image_layers.0.width', 512)
        ->assertJsonPath('manifest.image_layers.0.height', 512);
});

function seedFakeBirdsEyeMap(): void
{
    File::ensureDirectoryExists(public_path('maps/regions'));

    $image = imagecreatetruecolor(512, 512);
    $green = imagecolorallocate($image, 96, 150, 66);
    $blue = imagecolorallocate($image, 44, 96, 180);
    imagefilledrectangle($image, 0, 0, 255, 511, $green);
    imagefilledrectangle($image, 256, 0, 511, 511, $blue);
    imagepng($image, public_path(REAL_REGION_MAP_PATH));
    imagedestroy($image);
}

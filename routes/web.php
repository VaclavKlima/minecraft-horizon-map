<?php

use App\Http\Controllers\MapInterfaceController;
use App\Http\Controllers\RegionDataController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/regions', [RegionDataController::class, 'index']);
Route::get('/api/regions/{regionFile}', [RegionDataController::class, 'show'])
    ->where('regionFile', 'r\.-?\d+\.-?\d+\.mca');
Route::post('/api/maps/birdeye/render', [RegionDataController::class, 'renderBirdsEye']);
Route::get('/api/maps/manifest', [MapInterfaceController::class, 'manifest']);
Route::get('/api/maps/tiles/{zoom}/{x}/{y}.png', [MapInterfaceController::class, 'tile'])
    ->whereNumber('zoom')
    ->whereNumber('x')
    ->whereNumber('y');
Route::get('/map', [MapInterfaceController::class, 'index']);

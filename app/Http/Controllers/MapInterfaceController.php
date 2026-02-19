<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReadMapRegionRequest;
use App\Services\MinecraftIsometricTileService;
use App\Services\MinecraftMapTileService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MapInterfaceController extends Controller
{
    public function __construct(
        private MinecraftMapTileService $minecraftMapTileService,
        private MinecraftIsometricTileService $minecraftIsometricTileService
    ) {}

    public function index(): View
    {
        return view('map-interface');
    }

    public function manifest(ReadMapRegionRequest $request): JsonResponse
    {
        $region = $request->string('region')->toString();
        $projection = $request->string('projection', 'birds-eye')->toString();

        try {
            $manifest = $projection === 'isometric'
                ? $this->minecraftIsometricTileService->getManifest($region !== '' ? $region : null, true)
                : $this->minecraftMapTileService->getManifest($region !== '' ? $region : null);
        } catch (RuntimeException $exception) {
            return response()->json([
                'available' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($manifest === null) {
            return response()->json([
                'available' => false,
                'message' => 'Map tiles are not ready yet. Queue generation jobs and run a queue worker.',
            ]);
        }

        return response()->json([
            'available' => true,
            'manifest' => $manifest,
            'projection' => $projection,
        ]);
    }

    public function tile(ReadMapRegionRequest $request, int $zoom, int $x, int $y): BinaryFileResponse
    {
        $region = $request->string('region')->toString();
        $projection = $request->string('projection', 'birds-eye')->toString();

        try {
            $tilePath = $projection === 'isometric'
                ? $this->minecraftIsometricTileService->getTilePath($zoom, $x, $y, $region !== '' ? $region : null, true, false)
                : $this->minecraftMapTileService->getTilePath($zoom, $x, $y, $region !== '' ? $region : null);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        if ($tilePath === null) {
            abort(404);
        }

        return response()->file($tilePath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}

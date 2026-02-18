<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReadRegionRequest;
use App\Http\Requests\RenderBirdsEyeMapRequest;
use App\Services\DispatchBirdsEyeMapBatch;
use App\Services\DispatchIsometricMapBatch;
use App\Services\MinecraftRegionReader;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

class RegionDataController extends Controller
{
    public function __construct(
        private MinecraftRegionReader $minecraftRegionReader,
        private DispatchBirdsEyeMapBatch $dispatchBirdsEyeMapBatch,
        private DispatchIsometricMapBatch $dispatchIsometricMapBatch
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'files' => $this->minecraftRegionReader->listRegionFiles(),
        ]);
    }

    public function show(ReadRegionRequest $request, string $regionFile): JsonResponse
    {
        try {
            $data = $this->minecraftRegionReader->readRegionFile(
                $regionFile,
                $request->integer('limit', 20),
                $request->boolean('include_nbt', false),
            );

            return response()->json($data);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }
    }

    public function renderBirdsEye(RenderBirdsEyeMapRequest $request): JsonResponse
    {
        try {
            $result = $this->dispatchBirdsEyeMapBatch->dispatch(
                $request->string('heightmap', 'WORLD_SURFACE')->toString()
            );

            return response()->json($result, 202);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function renderIsometric(RenderBirdsEyeMapRequest $request): JsonResponse
    {
        try {
            $result = $this->dispatchIsometricMapBatch->dispatch(
                $request->string('heightmap', 'WORLD_SURFACE')->toString()
            );

            return response()->json($result, 202);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}

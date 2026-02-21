<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReadRegionRequest;
use App\Http\Requests\RenderBirdsEyeMapRequest;
use App\Services\DispatchBirdsEyeMapBatch;
use App\Services\DispatchIsometricMapBatch;
use App\Services\MinecraftRegionReader;
use Illuminate\Bus\Batch;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;
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
                $request->string('heightmap', 'WORLD_SURFACE')->toString(),
                $this->priorityContextFromRequest($request)
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
                $request->string('heightmap', 'WORLD_SURFACE')->toString(),
                $this->priorityContextFromRequest($request)
            );

            return response()->json($result, 202);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function batchStatus(string $batchId): JsonResponse
    {
        try {
            $batch = Bus::findBatch($batchId);
        } catch (QueryException) {
            return response()->json([
                'message' => 'Batch storage is not available.',
            ], 503);
        }

        if (! $batch instanceof Batch) {
            return response()->json([
                'message' => 'Batch not found.',
            ], 404);
        }

        return response()->json([
            'id' => $batch->id,
            'name' => $batch->name,
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'processed_jobs' => $batch->processedJobs(),
            'failed_jobs' => $batch->failedJobs,
            'progress' => $batch->progress(),
            'finished' => $batch->finished(),
            'cancelled' => $batch->cancelled(),
        ]);
    }

    /**
     * @return array{
     *     focus_world_x:int|null,
     *     focus_world_z:int|null,
     *     viewport_min_world_x:int|null,
     *     viewport_min_world_z:int|null,
     *     viewport_max_world_x:int|null,
     *     viewport_max_world_z:int|null,
     *     priority_regions:array<int,string>
     * }
     */
    private function priorityContextFromRequest(RenderBirdsEyeMapRequest $request): array
    {
        $parseOptionalInt = static function (string $key) use ($request): ?int {
            if (! $request->has($key)) {
                return null;
            }

            return (int) $request->input($key);
        };

        return [
            'focus_world_x' => $parseOptionalInt('focus_world_x'),
            'focus_world_z' => $parseOptionalInt('focus_world_z'),
            'viewport_min_world_x' => $parseOptionalInt('viewport_min_world_x'),
            'viewport_min_world_z' => $parseOptionalInt('viewport_min_world_z'),
            'viewport_max_world_x' => $parseOptionalInt('viewport_max_world_x'),
            'viewport_max_world_z' => $parseOptionalInt('viewport_max_world_z'),
            'priority_regions' => array_values(array_unique(array_filter(
                array_map('strval', (array) $request->input('priority_regions', [])),
                static fn (string $region): bool => preg_match('/^r\.-?\d+\.-?\d+\.mca$/', $region) === 1
            ))),
        ];
    }
}

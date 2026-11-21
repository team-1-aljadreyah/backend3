<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SprintResource;
use App\Services\ProjectService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SprintController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $sprints = $this->projects->sprints($request->user(), $id);

        return ApiResponse::success(SprintResource::collection($sprints)->resolve($request));
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $sprint = $this->projects->createSprint($request->user(), $id, $request->all());

        return ApiResponse::success((new SprintResource($sprint))->resolve($request), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $sprint = $this->projects->updateSprint($request->user(), $id, $request->all());

        return ApiResponse::success((new SprintResource($sprint))->resolve($request));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->projects->deleteSprint($request->user(), $id);

        return ApiResponse::success(null);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $moveTo = $request->input('move_to');
        $sprint = $this->projects->closeSprint(
            $request->user(),
            $id,
            $moveTo === null ? null : (int) $moveTo
        );

        return ApiResponse::success((new SprintResource($sprint))->resolve($request));
    }
}

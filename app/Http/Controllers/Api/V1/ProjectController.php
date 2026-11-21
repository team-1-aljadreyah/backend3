<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectEventResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectSummaryResource;
use App\Services\ProjectService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->projects->listForUser($request->user());

        return ApiResponse::success(ProjectSummaryResource::collection($list)->resolve($request));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = $this->projects->show($request->user(), $id);

        return ApiResponse::success((new ProjectResource($project))->resolve($request));
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->projects->create($request->user(), $request->all());

        return ApiResponse::success((new ProjectResource($project))->resolve($request), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $project = $this->projects->update($request->user(), $id, $request->all());

        return ApiResponse::success((new ProjectResource($project))->resolve($request));
    }

    public function saveStatuses(Request $request, int $id): JsonResponse
    {
        $statuses = $request->input('statuses');
        $project = $this->projects->saveStatuses($request->user(), $id, is_array($statuses) ? $statuses : []);

        return ApiResponse::success((new ProjectResource($project))->resolve($request));
    }

    public function savePhases(Request $request, int $id): JsonResponse
    {
        $phases = $request->input('phases');
        $project = $this->projects->savePhases($request->user(), $id, is_array($phases) ? $phases : []);

        return ApiResponse::success((new ProjectResource($project))->resolve($request));
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $events = $this->projects->history($request->user(), $id);

        return ApiResponse::success(ProjectEventResource::collection($events)->resolve($request));
    }
}

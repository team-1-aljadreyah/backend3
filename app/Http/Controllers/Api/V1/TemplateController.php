<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTemplateResource;
use App\Services\ProjectService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->projects->templates($request->user());

        return ApiResponse::success(ProjectTemplateResource::collection($list)->resolve($request));
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $template = $this->projects->saveTemplate($request->user(), $id, $request->all());

        return ApiResponse::success((new ProjectTemplateResource($template))->resolve($request), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->projects->deleteTemplate($request->user(), $id);

        return ApiResponse::success(null);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BugEventResource;
use App\Http\Resources\BugResource;
use App\Services\BugService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BugController extends Controller
{
    public function __construct(private readonly BugService $bugs) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->bugs->list($request->user());

        return ApiResponse::success(BugResource::collection($list)->resolve($request));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $bug = $this->bugs->show($request->user(), $id);

        return ApiResponse::success((new BugResource($bug))->resolve($request));
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $bug = $this->bugs->report($request->user(), $id, $request->all());

        return ApiResponse::success((new BugResource($bug))->resolve($request), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $bug = $this->bugs->update($request->user(), $id, $request->all());

        return ApiResponse::success((new BugResource($bug))->resolve($request));
    }

    public function move(Request $request, int $id): JsonResponse
    {
        $bug = $this->bugs->move(
            $request->user(),
            $id,
            (string) $request->input('status', ''),
            $request->input('note')
        );

        return ApiResponse::success((new BugResource($bug))->resolve($request));
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $events = $this->bugs->history($request->user(), $id);

        return ApiResponse::success(BugEventResource::collection($events)->resolve($request));
    }
}

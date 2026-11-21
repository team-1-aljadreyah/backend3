<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Services\AnnouncementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->announcements->list($request->user());

        return ApiResponse::success(AnnouncementResource::collection($list)->resolve($request));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $a = $this->announcements->show($request->user(), $id);

        return ApiResponse::success((new AnnouncementResource($a))->resolve($request));
    }

    public function store(Request $request): JsonResponse
    {
        $a = $this->announcements->create($request->user(), $request->all());

        return ApiResponse::success((new AnnouncementResource($a))->resolve($request), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->announcements->remove($request->user(), $id);

        return ApiResponse::success(null);
    }

    public function markSeen(Request $request, int $id): JsonResponse
    {
        $this->announcements->markSeen($request->user(), $id);

        return ApiResponse::success(null);
    }

    public function reads(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->announcements->reads($request->user(), $id));
    }
}

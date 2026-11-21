<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MonitorNoteResource;
use App\Services\MonitoringService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    public function __construct(private readonly MonitoringService $monitoring) {}

    public function notes(Request $request): JsonResponse
    {
        $list = $this->monitoring->notes($request->user());

        return ApiResponse::success(MonitorNoteResource::collection($list)->resolve($request));
    }

    public function create(Request $request): JsonResponse
    {
        $note = $this->monitoring->create($request->user(), $request->all());

        return ApiResponse::success((new MonitorNoteResource($note))->resolve($request), 201);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $note = $this->monitoring->send($request->user(), $id);

        return ApiResponse::success((new MonitorNoteResource($note))->resolve($request));
    }

    public function inbox(Request $request): JsonResponse
    {
        $list = $this->monitoring->inbox($request->user());

        return ApiResponse::success(MonitorNoteResource::collection($list)->resolve($request));
    }

    public function answer(Request $request, int $id): JsonResponse
    {
        $note = $this->monitoring->answer($request->user(), $id, $request->all());

        return ApiResponse::success((new MonitorNoteResource($note))->resolve($request));
    }

    public function reportFacts(Request $request): JsonResponse
    {
        $facts = $this->monitoring->reportFacts(
            $request->user(),
            (string) $request->query('type', ''),
            (int) $request->query('id', 0),
            $request->query('from') !== null ? (string) $request->query('from') : null,
            $request->query('to') !== null ? (string) $request->query('to') : null,
        );

        return ApiResponse::success($facts);
    }
}

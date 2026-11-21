<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AdvancedSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvancedSettingsController extends Controller
{
    public function __construct(private readonly AdvancedSettingsService $advanced) {}

    public function get(Request $request): JsonResponse
    {
        return ApiResponse::success($this->advanced->get($request->user()));
    }

    public function save(Request $request): JsonResponse
    {
        return ApiResponse::success($this->advanced->save($request->user(), $request->all()));
    }

    public function history(Request $request): JsonResponse
    {
        return ApiResponse::success($this->advanced->history($request->user()));
    }

    public function live(Request $request): JsonResponse
    {
        return ApiResponse::success($this->advanced->liveFor($request->user()));
    }
}

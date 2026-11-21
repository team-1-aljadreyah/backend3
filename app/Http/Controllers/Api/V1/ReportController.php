<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function overview(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->overview($request->user()));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SearchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->search->search($request->user(), $request->query('q'));

        return ApiResponse::success($result);
    }
}

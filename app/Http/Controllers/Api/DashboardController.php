<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardStats $stats): JsonResponse
    {
        $range = $stats->resolveRange(
            $request->string('range')->toString() ?: null,
            $request->string('period')->toString() ?: null,
        );

        return response()->json($stats->remember($range));
    }
}

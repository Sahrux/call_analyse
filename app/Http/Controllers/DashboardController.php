<?php

namespace App\Http\Controllers;

use App\Services\DashboardStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardStats $stats): Response
    {
        $range = $stats->resolveRange(
            $request->string('range')->toString() ?: null,
            $request->string('period')->toString() ?: null,
        );

        return Inertia::render('Dashboard', $stats->remember($range));
    }
}

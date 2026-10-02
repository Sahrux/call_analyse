<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OperatorController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('api.dashboard');

    Route::get('/calls', [CallController::class, 'index'])->name('api.calls.index');
    Route::post('/calls', [CallController::class, 'store'])->name('api.calls.store');
    Route::post('/calls/reanalyze', [CallController::class, 'reanalyze'])->name('api.calls.reanalyze');
    Route::get('/calls/{call}', [CallController::class, 'show'])->name('api.calls.show');
    Route::get('/calls/{call}/audio', [CallController::class, 'audio'])->name('api.calls.audio');
    Route::patch('/calls/{call}/review', [CallController::class, 'updateReview'])->name('api.calls.review');
    Route::patch('/calls/{call}/segments/bulk-review', [CallController::class, 'bulkUpdateSegmentReviews'])
        ->name('api.calls.segments.bulk-review');
    Route::patch('/calls/{call}/segments/{segmentIndex}/review', [CallController::class, 'updateSegmentReview'])
        ->whereNumber('segmentIndex')
        ->name('api.calls.segments.review');
    Route::post('/calls/{call}/reprocess', [CallController::class, 'reprocess'])->name('api.calls.reprocess');
    Route::delete('/calls/{call}', [CallController::class, 'destroy'])->name('api.calls.destroy');

    Route::get('/operators', [OperatorController::class, 'index'])->name('api.operators.index');
    Route::post('/operators', [OperatorController::class, 'store'])->name('api.operators.store');
    Route::get('/operators/{operator}', [OperatorController::class, 'show'])->name('api.operators.show');
    Route::put('/operators/{operator}', [OperatorController::class, 'update'])->name('api.operators.update');
    Route::delete('/operators/{operator}', [OperatorController::class, 'destroy'])->name('api.operators.destroy');

    Route::get('/alerts', [AlertController::class, 'index'])->name('api.alerts.index');

    Route::get('/users', [UserController::class, 'index'])->name('api.users.index');
    Route::post('/users', [UserController::class, 'store'])->name('api.users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('api.users.show');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('api.users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('api.users.destroy');

    Route::get('/settings', [SettingController::class, 'show'])->name('api.settings.show');
    Route::put('/settings', [SettingController::class, 'update'])->name('api.settings.update');
});

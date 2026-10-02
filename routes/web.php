<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Models\Call;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/calls', fn () => Inertia::render('Calls/Index'))->name('calls.index');
    Route::get('/calls/create', fn () => Inertia::render('Calls/Create'))->name('calls.create');
    Route::get('/calls/{call}', fn (Call $call) => Inertia::render('Calls/Show', [
        'callId' => $call->id,
    ]))->name('calls.show');

    Route::get('/operators', fn () => Inertia::render('Operators/Index'))->name('operators.index');
    Route::get('/operators/create', fn () => Inertia::render('Operators/Form', [
        'operatorId' => null,
    ]))->name('operators.create');
    Route::get('/operators/{operator}/edit', fn (Operator $operator) => Inertia::render('Operators/Form', [
        'operatorId' => $operator->id,
    ]))->name('operators.edit');

    Route::get('/alerts', fn () => Inertia::render('Alerts/Index'))->name('alerts.index');

    Route::get('/users', fn () => Inertia::render('Users/Index'))->name('users.index');
    Route::get('/users/create', fn () => Inertia::render('Users/Form', [
        'userId' => null,
    ]))->name('users.create');
    Route::get('/users/{user}/edit', fn (User $user) => Inertia::render('Users/Form', [
        'userId' => $user->id,
    ]))->name('users.edit');

    Route::get('/settings', fn () => Inertia::render('Settings/Edit'))->name('settings.edit');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

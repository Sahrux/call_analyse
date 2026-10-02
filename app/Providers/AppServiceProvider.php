<?php

namespace App\Providers;

use App\Models\CallAnalysis;
use App\Services\DashboardStats;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        CallAnalysis::saved(function (CallAnalysis $analysis): void {
            $hasScore = $analysis->total_score !== null || $analysis->manual_score !== null;
            $flagged = (bool) $analysis->has_rudeness;

            if (! $hasScore && ! $flagged) {
                return;
            }

            if (! $analysis->wasRecentlyCreated && ! $analysis->wasChanged(['total_score', 'manual_score', 'has_rudeness'])) {
                return;
            }

            app(DashboardStats::class)->forget();
        });
    }
}

<?php

use App\Models\CallAnalysis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            if (! Schema::hasColumn('call_analyses', 'is_fake_scored')) {
                $table->boolean('is_fake_scored')->default(false)->after('has_interruption');
            }
        });

        // Backfill rows previously marked only via the fake-score placeholder summary.
        DB::table('call_analyses')
            ->where('ai_summary', CallAnalysis::FAKE_SCORE_SUMMARY)
            ->update(['is_fake_scored' => true]);
    }

    public function down(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            if (Schema::hasColumn('call_analyses', 'is_fake_scored')) {
                $table->dropColumn('is_fake_scored');
            }
        });
    }
};

<?php

use App\Services\CallProviderBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('transcription_provider')->nullable()->index();
            $table->string('transcription_model')->nullable();
            $table->string('analysis_provider')->nullable()->index();
            $table->string('analysis_model')->nullable();
        });

        app(CallProviderBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn([
                'transcription_provider',
                'transcription_model',
                'analysis_provider',
                'analysis_model',
            ]);
        });
    }
};

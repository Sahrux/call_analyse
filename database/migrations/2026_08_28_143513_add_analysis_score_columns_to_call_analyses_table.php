<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            if (! Schema::hasColumn('call_analyses', 'emotional_tone_score')) {
                $table->unsignedTinyInteger('emotional_tone_score')->nullable()->after('aggression_score');
            }

            if (! Schema::hasColumn('call_analyses', 'interruption_count')) {
                $table->unsignedSmallInteger('interruption_count')->nullable()->after('emotional_tone_score');
            }

            if (! Schema::hasColumn('call_analyses', 'inappropriate_words_count')) {
                $table->unsignedSmallInteger('inappropriate_words_count')->nullable()->after('interruption_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('call_analyses', 'emotional_tone_score') ? 'emotional_tone_score' : null,
                Schema::hasColumn('call_analyses', 'interruption_count') ? 'interruption_count' : null,
                Schema::hasColumn('call_analyses', 'inappropriate_words_count') ? 'inappropriate_words_count' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

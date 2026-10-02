<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            $table->dropColumn(['aggression_score', 'has_aggression']);
        });

        Schema::table('ser_scores', function (Blueprint $table) {
            $table->dropColumn('aggression_score');
        });

        DB::table('settings')->where('key', 'weight_aggression')->delete();
    }

    public function down(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            $table->unsignedTinyInteger('aggression_score')->nullable()->after('rudeness_score');
            $table->boolean('has_aggression')->default(false)->after('buyer_potential_score');
        });

        Schema::table('ser_scores', function (Blueprint $table) {
            $table->float('aggression_score')->nullable()->after('rudeness_score');
        });

        DB::table('settings')->updateOrInsert(
            ['key' => 'weight_aggression'],
            [
                'value' => '15',
                'group' => 'scoring_params',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
};

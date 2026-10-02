<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            $table->unsignedTinyInteger('manual_courtesy_score')->nullable()->after('manual_score');
            $table->unsignedTinyInteger('manual_operator_interruption_score')->nullable()->after('manual_courtesy_score');
            $table->unsignedTinyInteger('manual_customer_interruption_score')->nullable()->after('manual_operator_interruption_score');
            $table->unsignedTinyInteger('manual_rudeness_score')->nullable()->after('manual_customer_interruption_score');
            $table->unsignedTinyInteger('manual_emotional_tone_score')->nullable()->after('manual_rudeness_score');
            $table->unsignedTinyInteger('manual_inappropriate_score')->nullable()->after('manual_emotional_tone_score');
        });
    }

    public function down(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'manual_courtesy_score',
                'manual_operator_interruption_score',
                'manual_customer_interruption_score',
                'manual_rudeness_score',
                'manual_emotional_tone_score',
                'manual_inappropriate_score',
            ]);
        });
    }
};

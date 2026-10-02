<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ser_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->string('model_version');
            $table->float('courtesy_score')->nullable();
            $table->float('rudeness_score')->nullable();
            $table->float('aggression_score')->nullable();
            $table->float('emotional_tone_score')->nullable();
            $table->timestamp('scored_at');
            $table->timestamp('created_at')->useCurrent();

            // call_id is indexed by the foreign key constraint.
            $table->index('model_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ser_scores');
    }
};

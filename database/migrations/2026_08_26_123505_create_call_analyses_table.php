<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->longText('transcript')->nullable();
            $table->json('transcript_segments')->nullable();

            $table->unsignedTinyInteger('courtesy_score')->nullable();
            $table->unsignedTinyInteger('rudeness_score')->nullable();
            $table->unsignedTinyInteger('aggression_score')->nullable();
            $table->unsignedTinyInteger('emotional_tone_score')->nullable();
            $table->unsignedSmallInteger('interruption_count')->nullable();
            $table->unsignedSmallInteger('inappropriate_words_count')->nullable();
            $table->unsignedTinyInteger('total_score')->nullable();

            $table->unsignedTinyInteger('interruption_score')->nullable();
            $table->unsignedTinyInteger('emotion_score')->nullable();
            $table->unsignedTinyInteger('buyer_potential_score')->nullable();

            $table->boolean('has_aggression')->default(false);
            $table->boolean('has_rudeness')->default(false);
            $table->boolean('has_interruption')->default(false);

            $table->string('emotion_label')->nullable();
            $table->string('buyer_potential_label')->nullable();
            $table->text('ai_summary')->nullable();
            $table->json('raw_result')->nullable();

            $table->unsignedTinyInteger('manual_score')->nullable();
            $table->text('manual_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_analyses');
    }
};

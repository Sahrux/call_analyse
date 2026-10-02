<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('audio_original_name')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('language', 10)->default('az');
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'called_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};

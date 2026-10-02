<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            if (! Schema::hasColumn('call_analyses', 'operator_inappropriate_words_count')) {
                $table->unsignedSmallInteger('operator_inappropriate_words_count')->nullable()->after('inappropriate_words_count');
            }

            if (! Schema::hasColumn('call_analyses', 'customer_inappropriate_words_count')) {
                $table->unsignedSmallInteger('customer_inappropriate_words_count')->nullable()->after('operator_inappropriate_words_count');
            }

            if (! Schema::hasColumn('call_analyses', 'inappropriate_expressions')) {
                $table->json('inappropriate_expressions')->nullable()->after('customer_inappropriate_words_count');
            }

            if (! Schema::hasColumn('call_analyses', 'manual_operator_inappropriate_score')) {
                $table->unsignedTinyInteger('manual_operator_inappropriate_score')->nullable()->after('manual_inappropriate_score');
            }

            if (! Schema::hasColumn('call_analyses', 'manual_customer_inappropriate_score')) {
                $table->unsignedTinyInteger('manual_customer_inappropriate_score')->nullable()->after('manual_operator_inappropriate_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('call_analyses', 'manual_customer_inappropriate_score') ? 'manual_customer_inappropriate_score' : null,
                Schema::hasColumn('call_analyses', 'manual_operator_inappropriate_score') ? 'manual_operator_inappropriate_score' : null,
                Schema::hasColumn('call_analyses', 'inappropriate_expressions') ? 'inappropriate_expressions' : null,
                Schema::hasColumn('call_analyses', 'customer_inappropriate_words_count') ? 'customer_inappropriate_words_count' : null,
                Schema::hasColumn('call_analyses', 'operator_inappropriate_words_count') ? 'operator_inappropriate_words_count' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

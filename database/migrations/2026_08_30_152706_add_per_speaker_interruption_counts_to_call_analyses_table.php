<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            if (! Schema::hasColumn('call_analyses', 'operator_interruption_count')) {
                $table->unsignedSmallInteger('operator_interruption_count')->default(0)->after('interruption_count');
            }

            if (! Schema::hasColumn('call_analyses', 'customer_interruption_count')) {
                $table->unsignedSmallInteger('customer_interruption_count')->default(0)->after('operator_interruption_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('call_analyses', function (Blueprint $table) {
            if (Schema::hasColumn('call_analyses', 'customer_interruption_count')) {
                $table->dropColumn('customer_interruption_count');
            }

            if (Schema::hasColumn('call_analyses', 'operator_interruption_count')) {
                $table->dropColumn('operator_interruption_count');
            }
        });
    }
};

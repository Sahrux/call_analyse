<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $inappropriateWeight = (int) (DB::table('settings')->where('key', 'weight_inappropriate')->value('value') ?? 15);
        $operatorWeight = intdiv($inappropriateWeight, 2);
        $customerWeight = $inappropriateWeight - $operatorWeight;

        $this->upsertSetting('weight_operator_inappropriate', (string) $operatorWeight, $now);
        $this->upsertSetting('weight_customer_inappropriate', (string) $customerWeight, $now);

        DB::table('settings')->where('key', 'weight_inappropriate')->delete();
    }

    public function down(): void
    {
        $now = now();
        $operatorWeight = (int) (DB::table('settings')->where('key', 'weight_operator_inappropriate')->value('value') ?? 7);
        $customerWeight = (int) (DB::table('settings')->where('key', 'weight_customer_inappropriate')->value('value') ?? 8);

        $this->upsertSetting('weight_inappropriate', (string) ($operatorWeight + $customerWeight), $now);

        DB::table('settings')->whereIn('key', [
            'weight_operator_inappropriate',
            'weight_customer_inappropriate',
        ])->delete();
    }

    private function upsertSetting(string $key, string $value, mixed $now): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => $value,
                'group' => 'scoring_params',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
};

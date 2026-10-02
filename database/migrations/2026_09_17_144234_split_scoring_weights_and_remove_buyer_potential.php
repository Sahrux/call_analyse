<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $interruptionWeight = (int) (DB::table('settings')->where('key', 'weight_interruption')->value('value') ?? 20);
        $operatorWeight = intdiv($interruptionWeight, 2);
        $customerWeight = $interruptionWeight - $operatorWeight;

        $this->upsertSetting('weight_operator_interruption', (string) $operatorWeight, $now);
        $this->upsertSetting('weight_customer_interruption', (string) $customerWeight, $now);

        if (! DB::table('settings')->where('key', 'weight_inappropriate')->exists()) {
            $this->upsertSetting('weight_inappropriate', '15', $now);
        }

        DB::table('settings')->whereIn('key', [
            'weight_interruption',
            'weight_buyer_potential',
        ])->delete();
    }

    public function down(): void
    {
        $now = now();
        $operatorWeight = (int) (DB::table('settings')->where('key', 'weight_operator_interruption')->value('value') ?? 10);
        $customerWeight = (int) (DB::table('settings')->where('key', 'weight_customer_interruption')->value('value') ?? 10);

        $this->upsertSetting('weight_interruption', (string) ($operatorWeight + $customerWeight), $now);
        $this->upsertSetting('weight_buyer_potential', '15', $now);

        DB::table('settings')->whereIn('key', [
            'weight_operator_interruption',
            'weight_customer_interruption',
            'weight_inappropriate',
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

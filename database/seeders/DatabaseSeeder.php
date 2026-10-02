<?php

namespace Database\Seeders;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Operator;
use App\Models\Setting;
use App\Models\User;
use App\Services\CallScoringService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@callanalyse.az'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'supervisor@callanalyse.az'],
            [
                'name' => 'Supervisor',
                'password' => Hash::make('password'),
                'role' => User::ROLE_SUPERVISOR,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $defaults = [
            ...array_map(static fn (int $value): string => (string) $value, CallScoringService::WEIGHT_DEFAULTS),
            'score_gamma' => (string) CallScoringService::GAMMA_DEFAULT,
            'alert_score_threshold' => '40',
            'default_language' => 'az',
            'audio_retention_days' => '90',
        ];

        foreach ($defaults as $key => $value) {
            Setting::setValue($key, $value, str_starts_with($key, 'weight_') || $key === 'score_gamma' ? 'scoring_params' : 'general');
        }

        $operators = collect([
            ['name' => 'Aysel Məmmədova', 'employee_code' => 'OP-001', 'email' => 'aysel@example.com'],
            ['name' => 'Elvin Quliyev', 'employee_code' => 'OP-002', 'email' => 'elvin@example.com'],
            ['name' => 'Nigar Həsənova', 'employee_code' => 'OP-003', 'email' => 'nigar@example.com'],
            ['name' => 'Rəşad Əliyev', 'employee_code' => 'OP-004', 'email' => 'reshad@example.com'],
        ])->map(fn (array $data) => Operator::query()->updateOrCreate(
            ['employee_code' => $data['employee_code']],
            [...$data, 'is_active' => true, 'phone' => '+994501234567']
        ));

        $samples = [
            [
                'customer_name' => 'Kamran İsmayılov',
                'customer_phone' => '+994552221100',
                'status' => Call::STATUS_COMPLETED,
                'duration_seconds' => 312,
                'analysis' => [
                    'courtesy_score' => 85,
                    'interruption_score' => 80,
                    'rudeness_score' => 90,
                    'emotion_score' => 78,
                    'buyer_potential_score' => 74,
                    'total_score' => 84,
                    'has_rudeness' => false,
                    'has_interruption' => false,
                    'emotion_label' => 'sakit',
                    'buyer_potential_label' => 'yüksək',
                    'ai_summary' => 'Operator nəzakətli danışıb, müştəri məhsula maraqlı görünür.',
                    'transcript' => "Operator: Salam, necə kömək edə bilərəm?\nMüştəri: Qiymət haqqında məlumat almaq istəyirəm.\nOperator: Əlbəttə, tariflərimizi izah edim.",
                ],
            ],
            [
                'customer_name' => 'Leyla Babayeva',
                'customer_phone' => '+994703334455',
                'status' => Call::STATUS_COMPLETED,
                'duration_seconds' => 245,
                'analysis' => [
                    'courtesy_score' => 35,
                    'interruption_score' => 28,
                    'rudeness_score' => 30,
                    'emotion_score' => 40,
                    'buyer_potential_score' => 20,
                    'total_score' => 30,
                    'has_rudeness' => true,
                    'has_interruption' => true,
                    'emotion_label' => 'gərgin',
                    'buyer_potential_label' => 'aşağı',
                    'ai_summary' => 'Operator müştərinin sözünü kəsib və kobud ifadələr işlədib.',
                    'transcript' => "Müştəri: Mən izah etmək istəyirəm ki...\nOperator: Dayanın, mən bilirəm.\nMüştəri: Amma problem...\nOperator: Artıq dedim!",
                ],
            ],
            [
                'customer_name' => 'Orxan Cəfərov',
                'customer_phone' => '+994557778899',
                'status' => Call::STATUS_PENDING,
                'duration_seconds' => null,
                'analysis' => null,
            ],
            [
                'customer_name' => 'Sevinc Rəhimova',
                'customer_phone' => '+994512223344',
                'status' => Call::STATUS_COMPLETED,
                'duration_seconds' => 410,
                'analysis' => [
                    'courtesy_score' => 88,
                    'interruption_score' => 86,
                    'rudeness_score' => 90,
                    'emotion_score' => 82,
                    'buyer_potential_score' => 88,
                    'total_score' => 88,
                    'has_rudeness' => false,
                    'has_interruption' => false,
                    'emotion_label' => 'pozitiv',
                    'buyer_potential_label' => 'çox yüksək',
                    'ai_summary' => 'Müştəri almağa hazırdır, operator peşəkar davranıb.',
                    'transcript' => "Müştəri: Bu paketi almaq istəyirəm.\nOperator: Əla, sifarişi rəsmiləşdirək.\nMüştəri: Bəli, razıyam.",
                ],
            ],
            [
                'customer_name' => 'Tural Nəbiyev',
                'customer_phone' => '+994508889900',
                'status' => Call::STATUS_PROCESSING,
                'duration_seconds' => 180,
                'analysis' => null,
            ],
        ];

        foreach ($samples as $index => $sample) {
            $operator = $operators[$index % $operators->count()];

            $call = Call::query()->updateOrCreate(
                [
                    'customer_phone' => $sample['customer_phone'],
                    'customer_name' => $sample['customer_name'],
                ],
                [
                    'operator_id' => $operator->id,
                    'status' => $sample['status'],
                    'duration_seconds' => $sample['duration_seconds'],
                    'language' => 'az',
                    'called_at' => now()->subHours($index * 5),
                    'audio_original_name' => 'sample-call-'.($index + 1).'.mp3',
                ]
            );

            if ($sample['analysis']) {
                CallAnalysis::query()->updateOrCreate(
                    ['call_id' => $call->id],
                    [
                        ...$sample['analysis'],
                        'analyzed_at' => now()->subHours($index * 4),
                    ]
                );
            }
        }

        foreach ($operators as $operator) {
            $avg = CallAnalysis::query()
                ->whereHas('call', fn ($q) => $q->where('operator_id', $operator->id))
                ->avg('total_score');

            $operator->update(['avg_score' => $avg ? round((float) $avg, 2) : null]);
        }
    }
}

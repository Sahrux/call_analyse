<?php

namespace Database\Factories;

use App\Models\Call;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    protected $model = Call::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operator_id' => null,
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->numerify('050#######'),
            'audio_path' => 'calls/audio/'.fake()->uuid().'.mp3',
            'audio_original_name' => 'sample.mp3',
            'duration_seconds' => null,
            'language' => 'az',
            'status' => Call::STATUS_PENDING,
            'error_message' => null,
            'called_at' => now(),
        ];
    }
}

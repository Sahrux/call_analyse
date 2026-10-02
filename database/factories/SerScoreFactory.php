<?php

namespace Database\Factories;

use App\Models\Call;
use App\Models\SerScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerScore>
 */
class SerScoreFactory extends Factory
{
    protected $model = SerScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'call_id' => Call::factory(),
            'model_version' => 'v1',
            'courtesy_score' => fake()->randomFloat(2, 0, 100),
            'rudeness_score' => fake()->randomFloat(2, 0, 100),
            'emotional_tone_score' => fake()->randomFloat(2, 0, 100),
            'scored_at' => now(),
        ];
    }
}

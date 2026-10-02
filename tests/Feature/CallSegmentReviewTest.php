<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallSegmentReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_segment_review_updates_manual_fields_and_leaves_other_segments_untouched(): void
    {
        $user = User::factory()->create();

        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun. Salam.',
            'transcript_segments' => [
                [
                    'speaker' => 'operator',
                    'start' => 0.0,
                    'end' => 1.0,
                    'text' => 'Buyurun.',
                    'courtesy_score' => 90,
                    'rudeness_score' => 5,
                    'emotional_tone_score' => 88,
                ],
                [
                    'speaker' => 'musteri',
                    'start' => 1.5,
                    'end' => 2.5,
                    'text' => 'Salam.',
                    'courtesy_score' => 70,
                    'rudeness_score' => 10,
                    'emotional_tone_score' => 65,
                ],
            ],
        ]);

        $response = $this->actingAs($user)->patchJson("/api/calls/{$call->id}/segments/1/review", [
            'manual_courtesy_score' => 80,
            'manual_rudeness_score' => 12,
            'manual_emotional_tone_score' => 72,
        ]);

        $response->assertOk()
            ->assertJsonPath('segment.manual_courtesy_score', 80)
            ->assertJsonPath('segment.manual_rudeness_score', 12)
            ->assertJsonPath('segment.manual_emotional_tone_score', 72)
            ->assertJsonPath('segment.manual_reviewed', true)
            ->assertJsonPath('segment.manual_reviewed_by', $user->id);

        $analysis = CallAnalysis::query()->where('call_id', $call->id)->first();

        $this->assertFalse($analysis->transcript_segments[0]['manual_reviewed'] ?? false);
        $this->assertArrayNotHasKey('manual_reviewed_by', $analysis->transcript_segments[0]);
        $this->assertTrue($analysis->transcript_segments[1]['manual_reviewed']);
        $this->assertSame($user->id, $analysis->transcript_segments[1]['manual_reviewed_by']);
        $this->assertSame(70, $analysis->transcript_segments[1]['courtesy_score']);
    }

    public function test_update_segment_review_returns_404_for_invalid_segment_index(): void
    {
        $user = User::factory()->create();

        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun.',
            'transcript_segments' => [
                ['speaker' => 'operator', 'start' => 0.0, 'end' => 1.0, 'text' => 'Buyurun.'],
            ],
        ]);

        $response = $this->actingAs($user)->patchJson("/api/calls/{$call->id}/segments/3/review", [
            'manual_courtesy_score' => 80,
        ]);

        $response->assertNotFound();
    }
}

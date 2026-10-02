<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallBulkSegmentReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_segment_review_requires_authentication(): void
    {
        $call = Call::factory()->create();

        $this->patchJson("/api/calls/{$call->id}/segments/bulk-review", $this->payload())
            ->assertUnauthorized();
    }

    public function test_bulk_segment_review_overwrites_selected_segments_and_leaves_others_untouched(): void
    {
        $user = User::factory()->create();
        $call = $this->callWithSegments();

        $response = $this->actingAs($user)->patchJson(
            "/api/calls/{$call->id}/segments/bulk-review",
            $this->payload([
                'segment_ids' => [0, 2],
                'manual_courtesy_score' => 40,
                'manual_rudeness_score' => 15,
                'manual_emotional_tone_score' => 60,
            ]),
        );

        $response->assertOk()
            ->assertJsonPath('segments.0.id', 0)
            ->assertJsonPath('segments.0.segment.manual_courtesy_score', 40)
            ->assertJsonPath('segments.0.segment.manual_rudeness_score', 15)
            ->assertJsonPath('segments.0.segment.manual_emotional_tone_score', 60)
            ->assertJsonPath('segments.0.segment.manual_reviewed', true)
            ->assertJsonPath('segments.0.segment.manual_reviewed_by', $user->id)
            ->assertJsonPath('segments.1.id', 2)
            ->assertJsonPath('segments.1.segment.manual_courtesy_score', 40)
            ->assertJsonPath('segments.1.segment.courtesy_score', 55);

        $segments = CallAnalysis::query()->where('call_id', $call->id)->first()->transcript_segments;

        $this->assertCount(2, $response->json('segments'));
        $this->assertSame(40, $segments[0]['manual_courtesy_score']);
        $this->assertSame(90, $segments[0]['courtesy_score']);
        $this->assertSame(80, $segments[1]['manual_courtesy_score']);
        $this->assertSame(11, $segments[1]['manual_rudeness_score']);
        $this->assertSame(70, $segments[1]['manual_emotional_tone_score']);
        $this->assertSame(70, $segments[1]['courtesy_score']);
        $this->assertFalse($segments[1]['manual_reviewed'] ?? false);
        $this->assertSame(40, $segments[2]['manual_courtesy_score']);
        $this->assertTrue($segments[2]['manual_reviewed']);
        $this->assertSame($user->id, $segments[2]['manual_reviewed_by']);
        $this->assertSame('Sagolun.', $segments[2]['text']);
    }

    public function test_bulk_segment_review_rejects_an_unknown_segment_id_without_saving(): void
    {
        $user = User::factory()->create();
        $call = $this->callWithSegments();

        $this->actingAs($user)
            ->patchJson("/api/calls/{$call->id}/segments/bulk-review", $this->payload([
                'segment_ids' => [0, 9],
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.segment_ids.0', 'Segment tapılmadı.');

        $segments = CallAnalysis::query()->where('call_id', $call->id)->first()->transcript_segments;

        $this->assertArrayNotHasKey('manual_courtesy_score', $segments[0]);
        $this->assertSame(80, $segments[1]['manual_courtesy_score']);
    }

    public function test_bulk_segment_review_returns_404_when_the_call_has_no_analysis(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/calls/{$call->id}/segments/bulk-review", $this->payload())
            ->assertNotFound();
    }

    public function test_bulk_segment_review_rejects_missing_scores_and_duplicate_segment_ids(): void
    {
        $user = User::factory()->create();
        $call = $this->callWithSegments();

        $this->actingAs($user)
            ->patchJson("/api/calls/{$call->id}/segments/bulk-review", [
                'segment_ids' => [0, 0],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'segment_ids.1',
                'manual_courtesy_score',
                'manual_rudeness_score',
                'manual_emotional_tone_score',
            ]);
    }

    public function test_bulk_segment_review_rejects_a_score_above_100(): void
    {
        $user = User::factory()->create();
        $call = $this->callWithSegments();

        $this->actingAs($user)
            ->patchJson("/api/calls/{$call->id}/segments/bulk-review", $this->payload([
                'manual_courtesy_score' => 101,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['manual_courtesy_score']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'segment_ids' => [0],
            'manual_courtesy_score' => 50,
            'manual_rudeness_score' => 10,
            'manual_emotional_tone_score' => 70,
        ], $overrides);
    }

    private function callWithSegments(): Call
    {
        $call = Call::factory()->create([
            'status' => Call::STATUS_COMPLETED,
        ]);

        CallAnalysis::query()->create([
            'call_id' => $call->id,
            'transcript' => 'Buyurun. Salam. Sagolun.',
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
                    'manual_courtesy_score' => 80,
                    'manual_rudeness_score' => 11,
                    'manual_emotional_tone_score' => 70,
                ],
                [
                    'speaker' => 'operator',
                    'start' => 3.0,
                    'end' => 4.0,
                    'text' => 'Sagolun.',
                    'courtesy_score' => 55,
                    'rudeness_score' => 20,
                    'emotional_tone_score' => 50,
                ],
            ],
        ]);

        return $call;
    }
}

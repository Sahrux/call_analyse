<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallAnalysis;
use App\Services\CallProviderBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallProviderBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_marks_existing_transcripts_and_scores_as_gemini(): void
    {
        config([
            'whisper.gemini_transcription_model' => 'gemini-3.5-transcribe',
            'whisper.gemini_model' => 'gemini-3.6-flash',
        ]);

        $transcribed = Call::factory()->create();
        CallAnalysis::query()->create([
            'call_id' => $transcribed->id,
            'transcript' => 'Salam',
        ]);

        $scored = Call::factory()->create();
        CallAnalysis::query()->create([
            'call_id' => $scored->id,
            'transcript' => 'Buyurun',
            'courtesy_score' => 80,
            'total_score' => 70,
        ]);

        $empty = Call::factory()->create();

        app(CallProviderBackfill::class)->run();

        $transcribed->refresh();
        $scored->refresh();
        $empty->refresh();

        $this->assertSame('gemini', $transcribed->transcription_provider);
        $this->assertSame('gemini-3.5-transcribe', $transcribed->transcription_model);
        $this->assertNull($transcribed->analysis_provider);
        $this->assertNull($transcribed->analysis_model);

        $this->assertSame('gemini', $scored->transcription_provider);
        $this->assertSame('gemini-3.5-transcribe', $scored->transcription_model);
        $this->assertSame('gemini', $scored->analysis_provider);
        $this->assertSame('gemini-3.6-flash', $scored->analysis_model);

        $this->assertNull($empty->transcription_provider);
        $this->assertNull($empty->transcription_model);
        $this->assertNull($empty->analysis_provider);
        $this->assertNull($empty->analysis_model);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CallProviderBackfill
{
    public function run(): void
    {
        $transcriptionModel = (string) config('whisper.gemini_transcription_model', 'gemini-3.5-transcribe');
        $analysisModel = (string) config('whisper.gemini_model', 'gemini-3.6-flash');

        DB::table('call_analyses')
            ->whereNotNull('transcript')
            ->where('transcript', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($transcriptionModel): void {
                $callIds = $rows->pluck('call_id')->filter()->unique()->values()->all();

                if ($callIds === []) {
                    return;
                }

                DB::table('calls')->whereIn('id', $callIds)->update([
                    'transcription_provider' => 'gemini',
                    'transcription_model' => $transcriptionModel,
                ]);
            });

        DB::table('call_analyses')
            ->where(function ($query): void {
                $query->whereNotNull('courtesy_score')
                    ->orWhereNotNull('rudeness_score')
                    ->orWhereNotNull('emotional_tone_score')
                    ->orWhereNotNull('total_score');
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($analysisModel): void {
                $callIds = $rows->pluck('call_id')->filter()->unique()->values()->all();

                if ($callIds === []) {
                    return;
                }

                DB::table('calls')->whereIn('id', $callIds)->update([
                    'analysis_provider' => 'gemini',
                    'analysis_model' => $analysisModel,
                ]);
            });
    }
}

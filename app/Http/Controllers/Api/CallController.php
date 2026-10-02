<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TranscribeCallJob;
use App\Models\Call;
use App\Models\CallAnalysis;
use App\Models\Operator;
use App\Services\CallScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CallController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'transcription_provider' => ['nullable', Rule::in(['gemini', 'elevenlabs'])],
            'analysis_provider' => ['nullable', Rule::in(['gemini', 'openai'])],
        ]);

        $filters = $request->only(['search', 'status', 'operator_id', 'score_min', 'score_max', 'problem', 'fake_scored', 'transcription_provider', 'analysis_provider']);

        $calls = Call::query()
            ->with(['operator:id,name', 'analysis:id,call_id,total_score,manual_score,has_rudeness,has_interruption,is_fake_scored,buyer_potential_score,emotion_label'])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhere('audio_original_name', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['operator_id'] ?? null, fn ($q, $id) => $q->where('operator_id', $id))
            ->when(($filters['problem'] ?? null) === '1', function ($query) {
                $query->whereHas('analysis', function ($q) {
                    $q->where('has_rudeness', true)
                        ->orWhere('total_score', '<', 40);
                });
            })
            ->when(($filters['fake_scored'] ?? null) === 'fake', function ($query) {
                $query->whereHas('analysis', fn ($q) => $q->where('is_fake_scored', true));
            })
            ->when(($filters['fake_scored'] ?? null) === 'real', function ($query) {
                $query->where(function ($q) {
                    $q->whereDoesntHave('analysis')
                        ->orWhereHas('analysis', fn ($analysis) => $analysis->where('is_fake_scored', false));
                });
            })
            ->when(isset($filters['score_min']) && $filters['score_min'] !== '', function ($query) use ($filters) {
                $query->whereHas('analysis', fn ($q) => $q->whereRaw('COALESCE(manual_score, total_score) >= ?', [(int) $filters['score_min']]));
            })
            ->when(isset($filters['score_max']) && $filters['score_max'] !== '', function ($query) use ($filters) {
                $query->whereHas('analysis', fn ($q) => $q->whereRaw('COALESCE(manual_score, total_score) <= ?', [(int) $filters['score_max']]));
            })
            ->when(filled($filters['transcription_provider'] ?? null), function ($query) use ($filters) {
                $query->where('transcription_provider', $filters['transcription_provider']);
            })
            ->when(filled($filters['analysis_provider'] ?? null), function ($query) use ($filters) {
                $query->where('analysis_provider', $filters['analysis_provider']);
            })
            ->latest()
            ->paginate((int) $request->integer('per_page', 12))
            ->withQueryString()
            ->through(fn (Call $call) => [
                'id' => $call->id,
                'customer_name' => $call->customer_name,
                'customer_phone' => $call->customer_phone,
                'operator' => $call->operator?->name,
                'status' => $call->status,
                'language' => $call->language,
                'transcription_provider' => $call->transcription_provider,
                'transcription_model' => $call->transcription_model,
                'analysis_provider' => $call->analysis_provider,
                'analysis_model' => $call->analysis_model,
                'duration' => $call->duration_formatted,
                'score' => $call->effective_score,
                'is_fake_scored' => (bool) $call->analysis?->is_fake_scored,
                'has_rudeness' => (bool) $call->analysis?->has_rudeness,
                'has_interruption' => (bool) $call->analysis?->has_interruption,
                'buyer_potential_score' => $call->analysis?->buyer_potential_score,
                'called_at' => $call->called_at?->format('d.m.Y H:i'),
                'created_at' => $call->created_at?->format('d.m.Y H:i'),
            ]);

        return response()->json([
            'calls' => $calls,
            'total_count' => Call::query()->count(),
            'filters' => $filters,
            'operators' => Operator::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => [
                Call::STATUS_PENDING => 'Gözləyir',
                Call::STATUS_PROCESSING => 'İşlənir',
                Call::STATUS_COMPLETED => 'Hazır',
                Call::STATUS_FAILED => 'Xəta',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operator_id' => ['nullable', 'exists:operators,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'language' => ['required', Rule::in(['az', 'ru', 'en'])],
            'called_at' => ['nullable', 'date'],
            'audio' => ['required', 'file', 'mimes:mp3,wav,m4a,ogg,webm', 'max:51200'],
        ]);

        $file = $request->file('audio');
        $path = $file->store('calls/audio', 'public');

        $call = Call::query()->create([
            'operator_id' => $data['operator_id'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'language' => $data['language'],
            'called_at' => $data['called_at'] ?? now(),
            'audio_path' => $path,
            'audio_original_name' => $file->getClientOriginalName(),
            'status' => Call::STATUS_PENDING,
        ]);

        TranscribeCallJob::dispatch($call);

        return response()->json([
            'message' => 'Zəng uğurla yükləndi. Analiz növbəyə əlavə olundu.',
            'call' => ['id' => $call->id],
        ], 201);
    }

    public function show(Call $call, CallScoringService $scoring): JsonResponse
    {
        $call->load(['operator', 'analysis.reviewer:id,name', 'serScores']);

        $latestSerScore = $call->latestSerScore();

        return response()->json([
            'call' => [
                'id' => $call->id,
                'customer_name' => $call->customer_name,
                'customer_phone' => $call->customer_phone,
                'operator' => $call->operator ? [
                    'id' => $call->operator->id,
                    'name' => $call->operator->name,
                    'employee_code' => $call->operator->employee_code,
                ] : null,
                'status' => $call->status,
                'language' => $call->language,
                'transcription_provider' => $call->transcription_provider,
                'transcription_model' => $call->transcription_model,
                'analysis_provider' => $call->analysis_provider,
                'analysis_model' => $call->analysis_model,
                'duration' => $call->duration_formatted,
                'duration_seconds' => $call->duration_seconds,
                'audio_url' => $call->audioUrl(),
                'audio_original_name' => $call->audio_original_name,
                'error_message' => $call->error_message,
                'called_at' => $call->called_at?->format('d.m.Y H:i'),
                'created_at' => $call->created_at?->format('d.m.Y H:i'),
                'analysis' => $call->analysis ? [
                    'transcript' => $call->analysis->transcript,
                    'transcript_segments' => $call->analysis->transcript_segments,
                    'courtesy_score' => $call->analysis->courtesy_score,
                    'rudeness_score' => $call->analysis->rudeness_score,
                    'emotional_tone_score' => $call->analysis->emotional_tone_score,
                    'interruption_count' => $call->analysis->interruption_count,
                    'operator_interruption_count' => $call->analysis->operator_interruption_count,
                    'customer_interruption_count' => $call->analysis->customer_interruption_count,
                    'inappropriate_words_count' => $call->analysis->inappropriate_words_count,
                    'operator_inappropriate_words_count' => $call->analysis->operator_inappropriate_words_count,
                    'customer_inappropriate_words_count' => $call->analysis->customer_inappropriate_words_count,
                    'inappropriate_expressions' => $call->analysis->inappropriate_expressions,
                    'interruption_score' => $call->analysis->interruption_score,
                    'emotion_score' => $call->analysis->emotion_score,
                    'buyer_potential_score' => $call->analysis->buyer_potential_score,
                    'total_score' => $call->analysis->total_score,
                    'manual_score' => $call->analysis->manual_score,
                    'manual_courtesy_score' => $call->analysis->manual_courtesy_score,
                    'manual_operator_interruption_score' => $call->analysis->manual_operator_interruption_score,
                    'manual_customer_interruption_score' => $call->analysis->manual_customer_interruption_score,
                    'manual_rudeness_score' => $call->analysis->manual_rudeness_score,
                    'manual_emotional_tone_score' => $call->analysis->manual_emotional_tone_score,
                    'manual_inappropriate_score' => $call->analysis->manual_inappropriate_score,
                    'manual_operator_inappropriate_score' => $call->analysis->manual_operator_inappropriate_score,
                    'manual_customer_inappropriate_score' => $call->analysis->manual_customer_inappropriate_score,
                    'manual_note' => $call->analysis->manual_note,
                    'effective_score' => $call->effective_score,
                    'has_rudeness' => $call->analysis->has_rudeness,
                    'has_interruption' => $call->analysis->has_interruption,
                    'is_fake_scored' => (bool) $call->analysis->is_fake_scored,
                    'emotion_label' => $call->analysis->emotion_label,
                    'buyer_potential_label' => $call->analysis->buyer_potential_label,
                    'ai_summary' => $call->analysis->ai_summary,
                    'reviewed_by' => $call->analysis->reviewer?->name,
                    'analyzed_at' => $call->analysis->analyzed_at?->format('d.m.Y H:i'),
                ] : null,
                'ser_score' => $latestSerScore ? [
                    'model_version' => $latestSerScore->model_version,
                    'courtesy_score' => $latestSerScore->courtesy_score,
                    'rudeness_score' => $latestSerScore->rudeness_score,
                    'emotional_tone_score' => $latestSerScore->emotional_tone_score,
                    'ser_overall' => $scoring->calculateSerOverall($latestSerScore, $call->analysis),
                    'scored_at' => $latestSerScore->scored_at?->format('d.m.Y H:i'),
                ] : null,
            ],
            'score_weights' => $scoring->weights(),
            'score_gamma' => $scoring->gamma(),
            'operators' => Operator::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function updateReview(Request $request, Call $call, CallScoringService $scoring): JsonResponse
    {
        $data = $request->validate([
            'manual_courtesy_score' => ['required', 'integer', 'min:0', 'max:100'],
            'manual_operator_interruption_score' => ['required', 'integer', 'min:0', 'max:100'],
            'manual_customer_interruption_score' => ['required', 'integer', 'min:0', 'max:100'],
            'manual_rudeness_score' => ['required', 'integer', 'min:0', 'max:100'],
            'manual_emotional_tone_score' => ['required', 'integer', 'min:0', 'max:100'],
            'manual_operator_inappropriate_score' => ['required', 'integer', 'min:0', 'max:100'],
            'manual_customer_inappropriate_score' => ['required', 'integer', 'min:0', 'max:100'],
            'manual_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $analysis = $call->analysis ?: CallAnalysis::query()->create(['call_id' => $call->id]);

        $analysis->update([
            'manual_courtesy_score' => $data['manual_courtesy_score'],
            'manual_operator_interruption_score' => $data['manual_operator_interruption_score'],
            'manual_customer_interruption_score' => $data['manual_customer_interruption_score'],
            'manual_rudeness_score' => $data['manual_rudeness_score'],
            'manual_emotional_tone_score' => $data['manual_emotional_tone_score'],
            'manual_operator_inappropriate_score' => $data['manual_operator_inappropriate_score'],
            'manual_customer_inappropriate_score' => $data['manual_customer_inappropriate_score'],
            'manual_score' => $scoring->calculateTotalScore(
                courtesyScore: $data['manual_courtesy_score'],
                operatorInterruptionScore: $data['manual_operator_interruption_score'],
                customerInterruptionScore: $data['manual_customer_interruption_score'],
                rudenessScore: $data['manual_rudeness_score'],
                emotionalToneScore: $data['manual_emotional_tone_score'],
                operatorInappropriateScore: $data['manual_operator_inappropriate_score'],
                customerInappropriateScore: $data['manual_customer_inappropriate_score'],
            ),
            'manual_note' => $data['manual_note'] ?? null,
            'reviewed_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Manual qiymətləndirmə saxlanıldı.']);
    }

    public function updateSegmentReview(Request $request, Call $call, int $segmentIndex): JsonResponse
    {
        $data = $request->validate([
            'manual_courtesy_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'manual_rudeness_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'manual_emotional_tone_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $analysis = $call->analysis;

        if (! $analysis) {
            return response()->json(['message' => 'Segment tapılmadı.'], 404);
        }

        /** @var list<array<string, mixed>> $segments */
        $segments = $analysis->transcript_segments ?? [];

        if (! array_key_exists($segmentIndex, $segments)) {
            return response()->json(['message' => 'Segment tapılmadı.'], 404);
        }

        $segments[$segmentIndex] = $this->applySegmentManualScores(
            $segments[$segmentIndex],
            $data,
            $request->user()->id,
        );

        $analysis->update(['transcript_segments' => $segments]);

        return response()->json(['segment' => $segments[$segmentIndex]]);
    }

    public function bulkUpdateSegmentReviews(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate([
            'segment_ids' => ['required', 'array', 'min:1'],
            'segment_ids.*' => ['integer', 'distinct', 'min:0'],
            'manual_courtesy_score' => ['required', 'integer', 'min:0', 'max:100'],
            'manual_rudeness_score' => ['required', 'integer', 'min:0', 'max:100'],
            'manual_emotional_tone_score' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $analysis = $call->analysis;

        if (! $analysis) {
            return response()->json(['message' => 'Segment tapılmadı.'], 404);
        }

        /** @var list<array<string, mixed>> $segments */
        $segments = $analysis->transcript_segments ?? [];

        /** @var list<int> $segmentIds */
        $segmentIds = array_values(array_map('intval', $data['segment_ids']));

        $missingSegmentIds = array_values(array_filter(
            $segmentIds,
            fn (int $segmentId): bool => ! array_key_exists($segmentId, $segments),
        ));

        if ($missingSegmentIds !== []) {
            return response()->json([
                'message' => 'Segment tapılmadı.',
                'errors' => [
                    'segment_ids' => ['Segment tapılmadı.'],
                ],
            ], 422);
        }

        foreach ($segmentIds as $segmentId) {
            $segments[$segmentId] = $this->applySegmentManualScores(
                $segments[$segmentId],
                $data,
                $request->user()->id,
            );
        }

        $analysis->update(['transcript_segments' => $segments]);

        return response()->json([
            'segments' => array_map(
                fn (int $segmentId): array => [
                    'id' => $segmentId,
                    'segment' => $segments[$segmentId],
                ],
                $segmentIds,
            ),
        ]);
    }

    public function audio(Call $call): BinaryFileResponse
    {
        abort_if($call->audio_path === null, 404);

        $disk = Storage::disk('public');

        abort_unless($disk->exists($call->audio_path), 404);

        return response()->file($disk->path($call->audio_path), [
            'Content-Type' => $disk->mimeType($call->audio_path) ?: 'application/octet-stream',
        ]);
    }

    public function destroy(Call $call): JsonResponse
    {
        if ($call->audio_path) {
            Storage::disk('public')->delete($call->audio_path);
        }

        $call->delete();

        return response()->json(['message' => 'Zəng silindi.']);
    }

    public function reprocess(Call $call): JsonResponse
    {
        if ($call->status === Call::STATUS_PROCESSING) {
            return response()->json(['message' => 'Zəng artıq işlənir, gözləyin.'], 409);
        }

        $call->update([
            'status' => Call::STATUS_PENDING,
            'error_message' => null,
        ]);

        TranscribeCallJob::releaseUniqueLock($call);
        TranscribeCallJob::dispatch($call);

        return response()->json(['message' => 'Zəng yenidən analiz növbəsinə əlavə olundu.']);
    }

    public function reanalyze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'call_ids' => ['required', 'array', 'min:1'],
            'call_ids.*' => ['integer', 'distinct', 'exists:calls,id'],
        ]);

        /** @var list<int> $callIds */
        $callIds = array_values(array_map('intval', $data['call_ids']));

        $queuedIds = [];
        $skipped = [];

        DB::transaction(function () use ($callIds, &$queuedIds, &$skipped): void {
            $calls = Call::query()
                ->whereIn('id', $callIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($callIds as $callId) {
                $call = $calls->get($callId);

                if (! $call) {
                    $skipped[] = [
                        'id' => $callId,
                        'reason' => 'tapılmadı',
                    ];

                    continue;
                }

                if ($call->status !== Call::STATUS_FAILED) {
                    $skipped[] = [
                        'id' => $callId,
                        'reason' => 'status Xəta deyil',
                    ];

                    continue;
                }

                $call->update([
                    'status' => Call::STATUS_PENDING,
                    'error_message' => null,
                ]);

                $queuedIds[] = $callId;
            }
        });

        if ($queuedIds !== []) {
            $queuedCalls = Call::query()->whereIn('id', $queuedIds)->get();

            foreach ($queuedCalls as $call) {
                TranscribeCallJob::releaseUniqueLock($call);
                TranscribeCallJob::dispatch($call);
            }
        }

        Log::info('Bulk call reanalyze requested.', [
            'user_id' => $request->user()?->id,
            'requested_ids' => $callIds,
            'queued_ids' => $queuedIds,
            'skipped' => $skipped,
        ]);

        return response()->json([
            'message' => count($queuedIds) > 0
                ? count($queuedIds).' zəng yenidən analiz növbəsinə əlavə olundu.'
                : 'Yenidən analiz üçün uyğun zəng tapılmadı.',
            'queued_count' => count($queuedIds),
            'queued_ids' => $queuedIds,
            'skipped' => $skipped,
        ]);
    }

    /**
     * @param  array<string, mixed>  $segment
     * @param  array{manual_courtesy_score: int|null, manual_rudeness_score: int|null, manual_emotional_tone_score: int|null}  $scores
     * @return array<string, mixed>
     */
    private function applySegmentManualScores(array $segment, array $scores, int $reviewerId): array
    {
        return array_merge($segment, [
            'manual_courtesy_score' => $scores['manual_courtesy_score'],
            'manual_rudeness_score' => $scores['manual_rudeness_score'],
            'manual_emotional_tone_score' => $scores['manual_emotional_tone_score'],
            'manual_reviewed' => true,
            'manual_reviewed_by' => $reviewerId,
        ]);
    }
}

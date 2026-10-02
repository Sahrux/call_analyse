<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Call extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'operator_id',
        'customer_name',
        'customer_phone',
        'audio_path',
        'audio_original_name',
        'duration_seconds',
        'language',
        'status',
        'error_message',
        'called_at',
        'transcription_provider',
        'transcription_model',
        'analysis_provider',
        'analysis_model',
    ];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(CallAnalysis::class);
    }

    public function serScores(): HasMany
    {
        return $this->hasMany(SerScore::class);
    }

    public function latestSerScore(): ?SerScore
    {
        if ($this->relationLoaded('serScores')) {
            return $this->serScores
                ->sortByDesc(fn (SerScore $score): array => [
                    $score->scored_at?->timestamp ?? 0,
                    $score->id,
                ])
                ->first();
        }

        return $this->serScores()
            ->orderByDesc('scored_at')
            ->orderByDesc('id')
            ->first();
    }

    public function getEffectiveScoreAttribute(): ?int
    {
        if (! $this->analysis) {
            return null;
        }

        return $this->analysis->manual_score ?? $this->analysis->total_score;
    }

    public function getDurationFormattedAttribute(): string
    {
        if (! $this->duration_seconds) {
            return '—';
        }

        $minutes = intdiv($this->duration_seconds, 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function audioUrl(): ?string
    {
        if ($this->audio_path === null) {
            return null;
        }

        return route('api.calls.audio', $this);
    }
}

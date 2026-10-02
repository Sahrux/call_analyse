<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallAnalysis extends Model
{
    use HasFactory;

    /**
     * Summary text written by the temporary `calls:fake-score` repair command.
     */
    public const FAKE_SCORE_SUMMARY = 'Auto-generated fallback score — not analyzed by AI';

    protected $fillable = [
        'call_id',
        'transcript',
        'transcript_segments',
        'courtesy_score',
        'rudeness_score',
        'emotional_tone_score',
        'interruption_count',
        'operator_interruption_count',
        'customer_interruption_count',
        'inappropriate_words_count',
        'operator_inappropriate_words_count',
        'customer_inappropriate_words_count',
        'inappropriate_expressions',
        'total_score',
        'interruption_score',
        'emotion_score',
        'buyer_potential_score',
        'has_rudeness',
        'has_interruption',
        'is_fake_scored',
        'emotion_label',
        'buyer_potential_label',
        'ai_summary',
        'raw_result',
        'manual_score',
        'manual_courtesy_score',
        'manual_operator_interruption_score',
        'manual_customer_interruption_score',
        'manual_rudeness_score',
        'manual_emotional_tone_score',
        'manual_inappropriate_score',
        'manual_operator_inappropriate_score',
        'manual_customer_inappropriate_score',
        'manual_note',
        'reviewed_by',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'transcript_segments' => 'array',
            'inappropriate_expressions' => 'array',
            'raw_result' => 'array',
            'has_rudeness' => 'boolean',
            'has_interruption' => 'boolean',
            'is_fake_scored' => 'boolean',
            'analyzed_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

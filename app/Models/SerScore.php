<?php

namespace App\Models;

use Database\Factories\SerScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerScore extends Model
{
    /** @use HasFactory<SerScoreFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'call_id',
        'model_version',
        'courtesy_score',
        'rudeness_score',
        'emotional_tone_score',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'courtesy_score' => 'float',
            'rudeness_score' => 'float',
            'emotional_tone_score' => 'float',
            'scored_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}

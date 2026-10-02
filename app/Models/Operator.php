<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Operator extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'employee_code',
        'email',
        'phone',
        'is_active',
        'avg_score',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'avg_score' => 'decimal:2',
        ];
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }
}

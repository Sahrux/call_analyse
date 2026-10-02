<?php

namespace App\Contracts;

interface AnalysisProvider
{
    public function name(): string;

    public function model(): string;

    /**
     * @param  list<array{speaker?: string|null, start?: float|int|null, end?: float|int|null, text?: string|null}>  $segments
     * @return array{courtesy_score: int, rudeness_score: int, emotional_tone_score: int, inappropriate_expressions: list<array{speaker?: string, text?: string}>|null}
     */
    public function analyze(string $transcript, array $segments): array;
}

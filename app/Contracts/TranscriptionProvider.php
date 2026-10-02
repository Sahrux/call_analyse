<?php

namespace App\Contracts;

interface TranscriptionProvider
{
    public function name(): string;

    public function model(): string;

    /**
     * @return array{text: string, segments: list<array{start: ?float, end: ?float, text: string, speaker: ?string}>, duration_seconds: ?int, model: ?string, whisper_language: ?string}
     */
    public function transcribe(string $absoluteAudioPath, string $language): array;
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Whisper / Hugging Face transcription
    |--------------------------------------------------------------------------
    |
    | Local Python environment runs openai/whisper-large-v3 via transformers.
    | On CPU this can take a long time — keep the queue worker timeout and
    | DB_QUEUE_RETRY_AFTER higher than whisper.timeout.
    |
    */

    'model' => env('WHISPER_MODEL', 'openai/whisper-large-v3'),

    'python' => env(
        'WHISPER_PYTHON',
        PHP_OS_FAMILY === 'Windows'
            ? base_path('python/.venv/Scripts/python.exe')
            : base_path('python/.venv/bin/python')
    ),

    'script' => env('WHISPER_SCRIPT', base_path('python/transcribe.py')),

    /*
    | Absolute path to ffmpeg. Defaults to the project-local copy at python/bin.
    */
    'ffmpeg' => env(
        'WHISPER_FFMPEG',
        PHP_OS_FAMILY === 'Windows'
            ? base_path('python/bin/ffmpeg.exe')
            : base_path('python/bin/ffmpeg')
    ),

    'timeout' => (int) env('WHISPER_TIMEOUT', 120),

    'hf_token' => env('HF_TOKEN'),

    'gemini_api_key' => env('GEMINI_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Gemini models (Interactions API)
    |--------------------------------------------------------------------------
    |
    | gemini_model — general text analysis (call quality scoring).
    | gemini_transcription_model — audio transcription with diarization.
    |
    */

    'gemini_model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),

    'gemini_transcription_model' => env('GEMINI_TRANSCRIPTION_MODEL', 'gemini-3.5-transcribe'),

    'segment_quality_batch_size' => (int) env('GEMINI_SEGMENT_QUALITY_BATCH_SIZE', 25),

];

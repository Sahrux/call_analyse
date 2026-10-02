<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Acoustic SER inference service
    |--------------------------------------------------------------------------
    |
    | Local FastAPI service (python/ser_service) that scores call audio with a
    | trained wav2vec2 + LoRA model. Scores are stored append-only in ser_scores
    | keyed by model_version — never overwrite Gemini call_analyses scores.
    |
    */

    'service_url' => env('SER_SERVICE_URL', 'http://localhost:8001'),

    'timeout' => (int) env('SER_TIMEOUT', 30),

    'connect_timeout' => (int) env('SER_CONNECT_TIMEOUT', 3),

];

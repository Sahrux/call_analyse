<?php

namespace App\Http\Requests;

use App\Services\CallScoringService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $weightRules = [];

        foreach (array_keys(CallScoringService::WEIGHT_DEFAULTS) as $key) {
            $weightRules[$key] = ['required', 'integer', 'min:0', 'max:100'];
        }

        return [
            ...$weightRules,
            'score_gamma' => ['required', 'numeric', 'min:1', 'max:3', 'decimal:0,1'],
            'alert_score_threshold' => ['required', 'integer', 'min:0', 'max:100'],
            'default_language' => ['required', 'in:az,ru,en'],
            'audio_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'transcription_provider' => ['required', 'in:gemini,elevenlabs'],
            'analysis_provider' => ['required', 'in:gemini,openai'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->input('transcription_provider') === 'elevenlabs' && blank(config('services.elevenlabs.key'))) {
                    $validator->errors()->add('transcription_provider', 'ELEVENLABS_API_KEY təyin edilməyib');
                }

                if ($this->input('analysis_provider') === 'openai' && blank(config('services.openai.key'))) {
                    $validator->errors()->add('analysis_provider', 'OPENAI_API_KEY təyin edilməyib');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        $weights = [];

        foreach (CallScoringService::WEIGHT_DEFAULTS as $key => $default) {
            $weights[$key] = (string) $default;
        }

        return [
            ...$weights,
            'score_gamma' => (string) CallScoringService::GAMMA_DEFAULT,
            'alert_score_threshold' => '40',
            'default_language' => 'az',
            'audio_retention_days' => '90',
            'transcription_provider' => 'gemini',
            'analysis_provider' => 'gemini',
        ];
    }

    public static function storageGroup(string $key): string
    {
        if (str_starts_with($key, 'weight_') || $key === 'score_gamma') {
            return 'scoring_params';
        }

        return 'general';
    }
}

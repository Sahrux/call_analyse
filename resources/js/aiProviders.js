export function providerLabel(provider) {
    if (!provider) {
        return null;
    }

    const labels = {
        gemini: 'Gemini',
        elevenlabs: 'ElevenLabs',
        openai: 'OpenAI',
    };

    return labels[provider] ?? provider;
}

export function providerMeta(provider, model) {
    const label = providerLabel(provider);

    if (!label) {
        return '—';
    }

    return model ? `${label} (${model})` : label;
}

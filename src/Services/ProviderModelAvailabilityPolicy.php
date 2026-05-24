<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

/**
 * Classifies live provider model rows for cache exposure, pricing metadata, and safe auto-fallback behavior.
 */
final class ProviderModelAvailabilityPolicy
{
    /** @var array<int, string> */
    private const CLOUDFLARE_CHAT_COMPLETIONS_MODELS = [
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    ];

    /** @var array<int, string> */
    private const GOOGLE_GENERATE_CONTENT_AUTO_MODELS = [
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
    ];

    /** @var array<int, string> */
    private const GOOGLE_NON_CHAT_MODEL_MARKERS = [
        'embedding',
        'embed-',
        '-embed',
        'imagen',
        'image',
        'veo',
        'tts',
        'audio',
        'live',
        'dialog',
        'bidi',
        'aqa',
    ];

    /**
     * Determine whether a live model row should be cached for the provider key.
     *
     * @param  array<string, mixed>  $model
     */
    public function shouldCacheLiveModel(string $platform, array $model, bool $hasCuratedMetadata): bool
    {
        return filled($model['model_id'] ?? null);
    }

    /**
     * Determine whether a cached model row represents a free-tier/anonymous model.
     *
     * @param  array<string, mixed>  $model
     */
    public function isFree(string $platform, array $model, bool $hasCuratedMetadata): bool
    {
        if ($platform === 'nvidia') {
            return true;
        }

        return $hasCuratedMetadata || $this->looksFree($platform, (string) $model['model_id']);
    }

    /**
     * Return the best budget label for a cached model row.
     *
     * @param  array<string, mixed>  $model
     */
    public function budgetLabel(string $platform, array $model, bool $isFree): ?string
    {
        if (isset($model['budget_label'])) {
            return (string) $model['budget_label'];
        }

        if ($platform === 'nvidia') {
            return 'credits-based';
        }

        if (! $isFree) {
            return 'credits-based';
        }

        return null;
    }

    /**
     * Determine whether a live cached model should be represented in the routable model table.
     *
     * @param  array<string, mixed>  $providerDefinition
     */
    public function shouldCreateRoutableModelRow(string $platform, array $providerDefinition): bool
    {
        return filled($providerDefinition['adapter'] ?? null);
    }

    /**
     * Determine whether a newly discovered routable model may participate in default auto fallback.
     *
     * @param  array<string, mixed>  $providerDefinition
     * @param  array<string, mixed>  $model
     */
    public function shouldEnableAutoFallback(string $platform, array $providerDefinition, array $model): bool
    {
        if (! $this->shouldCreateRoutableModelRow($platform, $providerDefinition)) {
            return false;
        }

        return $this->isChatCompatibleForAuto($platform, $model);
    }

    /**
     * Determine whether a cached/discovered model row is safe for automatic chat routing.
     *
     * @param  array<string, mixed>  $model
     */
    public function isChatCompatibleForAuto(string $platform, array $model): bool
    {
        $modelId = $this->normalizedModelId($model);

        return match ($platform) {
            'cloudflare' => in_array($modelId, self::CLOUDFLARE_CHAT_COMPLETIONS_MODELS, true),
            'google' => $this->isGoogleGenerateContentAutoModel($modelId, $model),
            default => true,
        };
    }

    /**
     * Determine whether a Gemini model is safe for this package's native generateContent adapter.
     *
     * @param  array<string, mixed>  $model
     */
    private function isGoogleGenerateContentAutoModel(string $modelId, array $model): bool
    {
        foreach (self::GOOGLE_NON_CHAT_MODEL_MARKERS as $marker) {
            if (str_contains($modelId, $marker)) {
                return false;
            }
        }

        $rawMetadata = is_array($model['raw_metadata'] ?? null) ? $model['raw_metadata'] : [];
        $methods = array_values(array_map('strval', (array) data_get($rawMetadata, 'supportedGenerationMethods', [])));

        if ($methods !== []
            && ! in_array('generateContent', $methods, true)
            && ! in_array('streamGenerateContent', $methods, true)) {
            return false;
        }

        return in_array($modelId, self::GOOGLE_GENERATE_CONTENT_AUTO_MODELS, true);
    }

    /**
     * Return a normalized provider model identifier from a cache/discovery row.
     *
     * @param  array<string, mixed>  $model
     */
    private function normalizedModelId(array $model): string
    {
        $modelId = strtolower(trim((string) ($model['model_id'] ?? data_get($model, 'raw_metadata.name', ''))));

        return str_starts_with($modelId, 'models/') ? substr($modelId, 7) : $modelId;
    }

    /**
     * Infer whether a live provider model identifier should be treated as free/anonymous for cache exposure.
     */
    private function looksFree(string $platform, string $modelId): bool
    {
        if (str_ends_with($modelId, ':free')) {
            return true;
        }

        return in_array($platform, ['kilo', 'pollinations', 'llm7'], true);
    }
}

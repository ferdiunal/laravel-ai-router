<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

/**
 * Classifies live provider model rows for cache exposure, pricing metadata, and safe auto-fallback behavior.
 */
final class ProviderModelAvailabilityPolicy
{
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
        return $this->shouldCreateRoutableModelRow($platform, $providerDefinition);
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

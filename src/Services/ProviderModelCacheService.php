<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

use Ferdiunal\LaravelAiRouter\Adapters\ProviderAdapterRegistry;
use Ferdiunal\LaravelAiRouter\Catalog\ModelCatalog;
use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Exceptions\ProviderAuthenticationException;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Refreshes, filters, and exposes provider-label-scoped available model cache rows for routing and model selection.
 */
final class ProviderModelCacheService
{
    /**
     * Initialize the cache service with the adapter registry used for live provider model discovery.
     */
    public function __construct(
        private readonly ProviderAdapterRegistry $adapters,
        private readonly ProviderModelAvailabilityPolicy $availability,
    ) {}

    /**
     * Refresh the available-model cache for a provider key using live provider data or curated fallback rows when safe.
     *
     * @return array<int, LaravelAiRouterProviderModelCache>
     */
    public function refreshForKey(LaravelAiRouterProviderKey $key): array
    {
        $models = [];
        $source = 'live';

        if (! $this->adapters->has($key->platform)) {
            $this->disableCacheRows($key);

            return [];
        }

        try {
            $models = $this->adapters->for($key->platform)->models($key->credentialForProvider());
        } catch (ProviderAuthenticationException) {
            $this->disableCacheRows($key);
            $this->markKeyInvalid($key);

            return [];
        } catch (Throwable) {
            $models = [];
        }

        $models = $this->filterAndEnrich((string) $key->platform, $models);

        if ($models === []) {
            $models = $this->curatedModels((string) $key->platform);
            $source = 'curated';
        }

        $this->ensureRoutableLiveModels((string) $key->platform, $models, $source);

        $this->disableCacheRows($key);

        $rows = [];
        foreach ($models as $model) {
            $rows[] = LaravelAiRouterProviderModelCache::query()->updateOrCreate(
                [
                    'provider_key_id' => $key->getKey(),
                    'model_id' => $model['model_id'],
                ],
                [
                    'platform' => $key->platform,
                    'provider_label' => $key->label,
                    'display_name' => $model['display_name'] ?? $model['model_id'],
                    'context_window' => $model['context_window'] ?? null,
                    'rpm_limit' => $model['rpm_limit'] ?? null,
                    'rpd_limit' => $model['rpd_limit'] ?? null,
                    'tpm_limit' => $model['tpm_limit'] ?? null,
                    'tpd_limit' => $model['tpd_limit'] ?? null,
                    'budget_label' => $model['budget_label'] ?? null,
                    'supports_tools' => $model['supports_tools'] ?? null,
                    'is_free' => (bool) ($model['is_free'] ?? true),
                    'enabled' => true,
                    'source' => $source,
                    'raw_metadata' => $model['raw_metadata'] ?? null,
                    'checked_at' => now(),
                ],
            );
        }

        $key->forceFill([
            'models_cached_at' => now(),
            'models_cache_expires_at' => now()->addMinutes((int) config('laravel-ai-router.models.cache_ttl_minutes', 1440)),
            'last_checked_at' => now(),
        ])->save();

        return $rows;
    }

    /**
     * Return cached available model identifiers, optionally scoped by provider and label, with optional auto routing included.
     *
     * @return array<int, string>
     */
    public function modelIds(?string $provider = null, ?string $label = null, bool $includeAuto = true): array
    {
        $ids = [];

        try {
            $routablePlatforms = $this->routablePlatforms();

            $query = LaravelAiRouterProviderModelCache::query()
                ->where('enabled', true)
                ->whereIn('platform', $routablePlatforms)
                ->whereHas('providerKey', function ($query): void {
                    $query->where('enabled', true)
                        ->where('status', '!=', 'invalid')
                        ->where(function ($query): void {
                            $query->whereNull('models_cache_expires_at')
                                ->orWhere('models_cache_expires_at', '>=', now());
                        });
                });

            if ($provider !== null) {
                $query->where('platform', $provider);
            }

            if ($label !== null) {
                $query->where('provider_label', $label);
            }

            $ids = $query->orderBy('platform')->orderBy('model_id')->pluck('model_id')->unique()->values()->all();
        } catch (QueryException) {
            $ids = [];
        }

        if ($ids === [] && $label === null) {
            $routablePlatforms = $routablePlatforms ?? $this->routablePlatforms();

            $ids = collect(ModelCatalog::all())
                ->whereIn('platform', $routablePlatforms)
                ->when($provider !== null, fn ($models) => $models->where('platform', $provider))
                ->where('enabled', true)
                ->pluck('model_id')
                ->unique()
                ->values()
                ->all();
        }

        return $includeAuto ? array_values(array_unique(['auto', ...$ids])) : array_values(array_unique($ids));
    }

    /**
     * Return the first enabled fallback model identifier from the package catalog.
     */
    public function firstAvailableModelId(): ?string
    {
        return LaravelAiRouterFallback::query()
            ->where('laravel_ai_router_fallbacks.enabled', true)
            ->join('laravel_ai_router_models', 'laravel_ai_router_models.id', '=', 'laravel_ai_router_fallbacks.laravel_ai_router_model_id')
            ->where('laravel_ai_router_models.enabled', true)
            ->whereIn('laravel_ai_router_models.platform', $this->routablePlatforms())
            ->orderBy('laravel_ai_router_models.intelligence_rank')
            ->orderBy('laravel_ai_router_models.id')
            ->value('laravel_ai_router_models.model_id');
    }

    /**
     * Return the preferred quality-oriented model identifier available to the package provider.
     */
    public function smartestAvailableModelId(): ?string
    {
        return $this->firstAvailableModelId();
    }

    /**
     * Return the number of currently exposed cached available models for a healthy provider key.
     */
    public function cachedCountForKey(LaravelAiRouterProviderKey $key): int
    {
        if (! $this->keyCanExposeCachedModels($key)) {
            return 0;
        }

        return count($this->cachedModelsForKey($key));
    }

    /**
     * Return filtered cached available model rows for a routable, enabled, non-invalid, non-expired provider key.
     *
     * @return array<int, LaravelAiRouterProviderModelCache>
     */
    public function cachedModelsForKey(LaravelAiRouterProviderKey $key): array
    {
        if (! $this->keyCanExposeCachedModels($key)) {
            return [];
        }

        try {
            return LaravelAiRouterProviderModelCache::query()
                ->where('provider_key_id', $key->getKey())
                ->where('platform', $key->platform)
                ->where('provider_label', $key->label)
                ->where('enabled', true)
                ->orderBy('model_id')
                ->get()
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Return searchable model-choice labels for auto routing selection from a healthy provider key cache.
     *
     * @return array<string, string>
     */
    public function choicesForKey(LaravelAiRouterProviderKey $key, bool $includeAuto = true): array
    {
        $choices = $includeAuto ? ['auto' => 'Auto — route requests across healthy cached available models'] : [];

        foreach ($this->cachedModelsForKey($key) as $model) {
            $toolSupport = match ($model->supports_tools) {
                true => 'tools',
                false => 'no tools',
                default => null,
            };

            $details = array_filter([
                $model->display_name,
                $model->context_window !== null ? 'ctx '.$model->context_window : null,
                $toolSupport,
                $model->is_free ? 'free' : null,
                $model->budget_label,
                $model->source,
            ]);

            $choices[$model->model_id] = $model->platform.' / '.$model->provider_label.' — '.implode(' · ', $details);
        }

        return $choices;
    }

    /**
     * Filter live provider model rows to available candidates and enrich them with curated metadata when available.
     *
     * @param  array<int, array<string, mixed>>  $liveModels
     * @return array<int, array<string, mixed>>
     */
    private function filterAndEnrich(string $platform, array $liveModels): array
    {
        if ($liveModels === []) {
            return [];
        }

        $curated = collect($this->curatedModels($platform))->keyBy('model_id');

        return collect($liveModels)
            ->filter(function (array $model) use ($platform, $curated): bool {
                $modelId = (string) $model['model_id'];

                return $this->availability->shouldCacheLiveModel($platform, $model, $curated->has($modelId));
            })
            ->map(function (array $model) use ($platform, $curated): array {
                $modelId = (string) $model['model_id'];
                $metadata = $curated->get($modelId, []);
                $merged = [
                    ...$metadata,
                    ...Arr::whereNotNull($model),
                    '_has_curated_metadata' => $metadata !== [],
                    'model_id' => $modelId,
                    'display_name' => (string) ($metadata['display_name'] ?? $model['display_name'] ?? $modelId),
                ];

                $isFree = $this->availability->isFree($platform, $merged, $metadata !== []);
                $budgetLabel = $this->availability->budgetLabel($platform, $merged, $isFree);
                if ($budgetLabel !== null) {
                    $merged['budget_label'] = $budgetLabel;
                }

                $merged['is_free'] = $isFree;

                return $merged;
            })
            ->values()
            ->all();
    }

    /**
     * Create runtime model and fallback rows for live models that can be routed by the package.
     *
     * @param  array<int, array<string, mixed>>  $models
     */
    private function ensureRoutableLiveModels(string $platform, array $models, string $source): void
    {
        if ($source !== 'live' || $models === []) {
            return;
        }

        try {
            $definition = ProviderCatalog::get($platform);
        } catch (\InvalidArgumentException) {
            return;
        }

        if (! $this->availability->shouldCreateRoutableModelRow($platform, $definition)) {
            return;
        }

        $nextPriority = ((int) LaravelAiRouterFallback::query()->max('priority')) + 1;

        foreach ($models as $model) {
            $row = LaravelAiRouterModel::query()->updateOrCreate(
                [
                    'platform' => $platform,
                    'model_id' => $model['model_id'],
                ],
                [
                    'display_name' => $model['display_name'] ?? $model['model_id'],
                    'intelligence_rank' => $model['intelligence_rank'] ?? 1000,
                    'speed_rank' => $model['speed_rank'] ?? 1000,
                    'rpm_limit' => $model['rpm_limit'] ?? null,
                    'rpd_limit' => $model['rpd_limit'] ?? null,
                    'tpm_limit' => $model['tpm_limit'] ?? null,
                    'tpd_limit' => $model['tpd_limit'] ?? null,
                    'budget_label' => $model['budget_label'] ?? null,
                    'context_window' => $model['context_window'] ?? null,
                    'enabled' => true,
                ],
            );

            $fallback = LaravelAiRouterFallback::query()->firstOrNew([
                'laravel_ai_router_model_id' => $row->getKey(),
            ]);

            if (! $fallback->exists) {
                $fallback->priority = $nextPriority++;
            }

            $autoEligible = $this->availability->shouldEnableAutoFallback($platform, $definition, $model);
            $hasEnabledCuratedFallback = (bool) ($model['_has_curated_metadata'] ?? false)
                && $fallback->exists
                && (bool) $fallback->enabled;
            $enabledForAuto = $autoEligible || $hasEnabledCuratedFallback;

            $fallback->forceFill([
                'enabled' => $enabledForAuto,
                'penalty' => 0,
            ])->save();
        }
    }

    /**
     * Return curated enabled model metadata for one provider platform.
     *
     * @return array<int, array<string, mixed>>
     */
    private function curatedModels(string $platform): array
    {
        return collect(ModelCatalog::all())
            ->where('platform', $platform)
            ->where('enabled', true)
            ->values()
            ->all();
    }

    /**
     * Persist provider-key invalidation metadata after an authentication failure.
     */
    private function markKeyInvalid(LaravelAiRouterProviderKey $key): void
    {
        $key->forceFill([
            'status' => 'invalid',
            'last_checked_at' => now(),
        ])->save();
    }

    /**
     * Determine whether a provider key is allowed to expose cached available models to routing or default selection.
     */
    private function keyCanExposeCachedModels(LaravelAiRouterProviderKey $key): bool
    {
        if (! in_array($key->platform, $this->routablePlatforms(), true)) {
            return false;
        }

        if (! $key->enabled || $key->status === 'invalid') {
            return false;
        }

        return $key->models_cache_expires_at === null || ! $key->models_cache_expires_at->isPast();
    }

    /**
     * Disable existing model cache rows for a provider key before writing a refreshed cache set.
     */
    private function disableCacheRows(LaravelAiRouterProviderKey $key): void
    {
        LaravelAiRouterProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->update(['enabled' => false]);
    }

    /**
     * Return provider platforms that have registered adapter implementations.
     *
     * @return array<int, string>
     */
    private function routablePlatforms(): array
    {
        return collect(ProviderCatalog::all())
            ->keys()
            ->filter(fn (string $platform): bool => $this->adapters->has($platform))
            ->values()
            ->all();
    }
}

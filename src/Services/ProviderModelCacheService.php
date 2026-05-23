<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Services;

use Ferdiunal\AiDevApi\Adapters\ProviderAdapterRegistry;
use Ferdiunal\AiDevApi\Catalog\ModelCatalog;
use Ferdiunal\AiDevApi\Catalog\ProviderCatalog;
use Ferdiunal\AiDevApi\Exceptions\ProviderAuthenticationException;
use Ferdiunal\AiDevApi\Models\AiDevApiFallback;
use Ferdiunal\AiDevApi\Models\AiDevApiModel;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderModelCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Refreshes, filters, and exposes provider-label-scoped free model cache rows for routing and default model selection.
 */
final class ProviderModelCacheService
{
    /**
     * Initialize the cache service with the adapter registry used for live provider model discovery.
     */
    public function __construct(private readonly ProviderAdapterRegistry $adapters) {}

    /**
     * Refresh the free-model cache for a provider key using live provider data or curated fallback rows when safe.
     *
     * @return array<int, AiDevApiProviderModelCache>
     */
    public function refreshForKey(AiDevApiProviderKey $key): array
    {
        $models = [];
        $source = 'live';

        if (! $this->adapters->has($key->platform)) {
            $this->disableCacheRows($key);

            return [];
        }

        try {
            $models = $this->adapters->for($key->platform)->models((string) $key->key);
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

        $this->ensureRoutableCustomModels((string) $key->platform, $models, $source);

        $this->disableCacheRows($key);

        $rows = [];
        foreach ($models as $model) {
            $rows[] = AiDevApiProviderModelCache::query()->updateOrCreate(
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
                    'is_free' => true,
                    'enabled' => true,
                    'source' => $source,
                    'raw_metadata' => $model['raw_metadata'] ?? null,
                    'checked_at' => now(),
                ],
            );
        }

        $key->forceFill([
            'models_cached_at' => now(),
            'models_cache_expires_at' => now()->addMinutes((int) config('ai-dev-api.models.cache_ttl_minutes', 1440)),
            'last_checked_at' => now(),
        ])->save();

        return $rows;
    }

    /**
     * Return cached free model identifiers, optionally scoped by provider and label, with optional auto routing included.
     *
     * @return array<int, string>
     */
    public function modelIds(?string $provider = null, ?string $label = null, bool $includeAuto = true): array
    {
        $ids = [];

        try {
            $routablePlatforms = $this->routablePlatforms();

            $query = AiDevApiProviderModelCache::query()
                ->where('enabled', true)
                ->where('is_free', true)
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
     * Return the first enabled routable model identifier from the package catalog.
     */
    public function firstAvailableModelId(): ?string
    {
        return AiDevApiModel::query()
            ->where('enabled', true)
            ->whereIn('platform', $this->routablePlatforms())
            ->orderBy('intelligence_rank')
            ->orderBy('id')
            ->value('model_id');
    }

    /**
     * Return the preferred quality-oriented model identifier available to the package provider.
     */
    public function smartestAvailableModelId(): ?string
    {
        return $this->firstAvailableModelId();
    }

    /**
     * Return the number of currently exposed cached free models for a healthy provider key.
     */
    public function cachedCountForKey(AiDevApiProviderKey $key): int
    {
        if (! $this->keyCanExposeCachedModels($key)) {
            return 0;
        }

        return count($this->cachedModelsForKey($key));
    }

    /**
     * Return filtered cached free model rows for a routable, enabled, non-invalid, non-expired provider key.
     *
     * @return array<int, AiDevApiProviderModelCache>
     */
    public function cachedModelsForKey(AiDevApiProviderKey $key): array
    {
        if (! $this->keyCanExposeCachedModels($key)) {
            return [];
        }

        try {
            return AiDevApiProviderModelCache::query()
                ->where('provider_key_id', $key->getKey())
                ->where('platform', $key->platform)
                ->where('provider_label', $key->label)
                ->where('enabled', true)
                ->where('is_free', true)
                ->orderBy('model_id')
                ->get()
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Return searchable model-choice labels for default model selection from a healthy provider key cache.
     *
     * @return array<string, string>
     */
    public function choicesForKey(AiDevApiProviderKey $key, bool $includeAuto = true): array
    {
        $choices = $includeAuto ? ['auto' => 'Auto — route requests across healthy cached free models'] : [];

        foreach ($this->cachedModelsForKey($key) as $model) {
            $details = array_filter([
                $model->display_name,
                $model->context_window !== null ? 'ctx '.$model->context_window : null,
                $model->supports_tools === true ? 'tools' : null,
                $model->budget_label,
                $model->source,
            ]);

            $choices[$model->model_id] = $model->platform.' / '.$model->provider_label.' — '.implode(' · ', $details);
        }

        return $choices;
    }

    /**
     * Filter live provider model rows to free candidates and enrich them with curated metadata when available.
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
            ->filter(fn (array $model): bool => $this->looksFree($platform, (string) $model['model_id']) || $curated->has((string) $model['model_id']))
            ->map(function (array $model) use ($curated): array {
                $metadata = $curated->get((string) $model['model_id'], []);

                return [
                    ...$metadata,
                    ...Arr::whereNotNull($model),
                    'model_id' => (string) $model['model_id'],
                    'display_name' => (string) ($metadata['display_name'] ?? $model['display_name'] ?? $model['model_id']),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Create runtime model and fallback rows for live custom-provider models that can be routed by the package.
     *
     * @param  array<int, array<string, mixed>>  $models
     */
    private function ensureRoutableCustomModels(string $platform, array $models, string $source): void
    {
        if ($source !== 'live' || $models === []) {
            return;
        }

        try {
            $definition = ProviderCatalog::get($platform);
        } catch (\InvalidArgumentException) {
            return;
        }

        if (($definition['custom'] ?? false) !== true) {
            return;
        }

        $nextPriority = ((int) AiDevApiFallback::query()->max('priority')) + 1;

        foreach ($models as $model) {
            $row = AiDevApiModel::query()->updateOrCreate(
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
                    'budget_label' => $model['budget_label'] ?? 'custom',
                    'context_window' => $model['context_window'] ?? null,
                    'enabled' => true,
                ],
            );

            $fallback = AiDevApiFallback::query()->firstOrNew([
                'ai_dev_api_model_id' => $row->getKey(),
            ]);

            if (! $fallback->exists) {
                $fallback->priority = $nextPriority++;
            }

            $fallback->forceFill([
                'enabled' => true,
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
     * Infer whether a live provider model identifier should be treated as free for cache exposure.
     */
    private function looksFree(string $platform, string $modelId): bool
    {
        if (str_ends_with($modelId, ':free')) {
            return true;
        }

        return in_array($platform, ['kilo', 'pollinations', 'llm7'], true);
    }

    /**
     * Persist provider-key invalidation metadata after an authentication failure.
     */
    private function markKeyInvalid(AiDevApiProviderKey $key): void
    {
        $key->forceFill([
            'status' => 'invalid',
            'last_checked_at' => now(),
        ])->save();
    }

    /**
     * Determine whether a provider key is allowed to expose cached free models to routing or default selection.
     */
    private function keyCanExposeCachedModels(AiDevApiProviderKey $key): bool
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
    private function disableCacheRows(AiDevApiProviderKey $key): void
    {
        AiDevApiProviderModelCache::query()
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

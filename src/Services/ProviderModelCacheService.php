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

final class ProviderModelCacheService
{
    public function __construct(private readonly ProviderAdapterRegistry $adapters) {}

    /** @return array<int, AiDevApiProviderModelCache> */
    public function refreshForKey(AiDevApiProviderKey $key): array
    {
        $models = [];
        $source = 'live';

        try {
            if ($this->adapters->has($key->platform)) {
                $models = $this->adapters->for($key->platform)->models((string) $key->key);
            }
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

    /** @return array<int, string> */
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

    public function firstAvailableModelId(): ?string
    {
        return AiDevApiModel::query()
            ->where('enabled', true)
            ->whereIn('platform', $this->routablePlatforms())
            ->orderBy('intelligence_rank')
            ->orderBy('id')
            ->value('model_id');
    }

    public function smartestAvailableModelId(): ?string
    {
        return $this->firstAvailableModelId();
    }

    public function cachedCountForKey(AiDevApiProviderKey $key): int
    {
        if ($key->models_cache_expires_at !== null && $key->models_cache_expires_at->isPast()) {
            return 0;
        }

        return (int) AiDevApiProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->where('enabled', true)
            ->where('is_free', true)
            ->count();
    }

    /**
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

    /** @return array<int, array<string, mixed>> */
    private function curatedModels(string $platform): array
    {
        return collect(ModelCatalog::all())
            ->where('platform', $platform)
            ->where('enabled', true)
            ->values()
            ->all();
    }

    private function looksFree(string $platform, string $modelId): bool
    {
        if (str_ends_with($modelId, ':free')) {
            return true;
        }

        return in_array($platform, ['kilo', 'pollinations', 'llm7'], true);
    }

    private function markKeyInvalid(AiDevApiProviderKey $key): void
    {
        $key->forceFill([
            'status' => 'invalid',
            'last_checked_at' => now(),
        ])->save();
    }

    private function disableCacheRows(AiDevApiProviderKey $key): void
    {
        AiDevApiProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->update(['enabled' => false]);
    }

    /** @return array<int, string> */
    private function routablePlatforms(): array
    {
        return collect(ProviderCatalog::all())
            ->keys()
            ->filter(fn (string $platform): bool => $this->adapters->has($platform))
            ->values()
            ->all();
    }
}

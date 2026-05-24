<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Routing;

use Ferdiunal\LaravelAiRouter\Adapters\ProviderAdapterRegistry;
use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Exceptions\ModelNotFoundException;
use Ferdiunal\LaravelAiRouter\Exceptions\NoAvailableModelException;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelAvailabilityPolicy;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Selects the best eligible provider key and model for a requested Laravel AI text operation.
 */
final class ModelRouter
{
    /**
     * Initialize the router with adapter availability checks and rate-window state.
     */
    public function __construct(
        private readonly ProviderAdapterRegistry $adapters,
        private readonly RateLimitWindowRepository $rateLimits,
        private readonly RouteCandidateSelector $candidateSelector,
        private readonly ProviderModelAvailabilityPolicy $modelAvailability,
    ) {}

    /**
     * Select an eligible provider key and model for the requested model identifier and token/tool requirements.
     *
     * @param  array<int, int>  $excludedKeyIds
     */
    public function route(?string $modelId = 'auto', int $estimatedTokens = 1000, bool $requiresTools = false, array $excludedKeyIds = []): RouteResult
    {
        if ($modelId !== null && $modelId !== '' && $modelId !== 'auto') {
            $models = LaravelAiRouterModel::query()
                ->where('model_id', $modelId)
                ->where('enabled', true)
                ->orderBy('id')
                ->get();

            if ($models->isEmpty()) {
                throw ModelNotFoundException::forModel($modelId);
            }

            foreach ($models as $model) {
                if (! $this->adapters->has($model->platform)) {
                    continue;
                }

                $key = $this->firstUsableKey($model, $estimatedTokens, $requiresTools, $excludedKeyIds);

                if ($key instanceof LaravelAiRouterProviderKey) {
                    return $this->toResult($model, $key);
                }
            }

            throw new NoAvailableModelException("No enabled valid key is available for model [{$modelId}].");
        }

        if ($this->autoStrategy() === 'random_provider' && $this->hasProviderModelCacheRows()) {
            return $this->routeSelectedProviderPool($estimatedTokens, $requiresTools, $excludedKeyIds);
        }

        $fallbacks = LaravelAiRouterFallback::query()
            ->where('enabled', true)
            ->get();

        return $this->routeFallbackPool($fallbacks, $estimatedTokens, $requiresTools, $excludedKeyIds);
    }

    /**
     * Persist retry penalty state for a route after a retryable provider failure.
     */
    public function recordRetryableFailure(RouteResult $route): void
    {
        $fallback = LaravelAiRouterFallback::query()->where('laravel_ai_router_model_id', $route->modelDbId)->first();

        if (! $fallback instanceof LaravelAiRouterFallback) {
            return;
        }

        $fallback->forceFill([
            'penalty' => min(
                (int) config('laravel-ai-router.routing.max_penalty', 10),
                ((int) $fallback->penalty) + (int) config('laravel-ai-router.routing.penalty_per_retryable_failure', 3),
            ),
            'penalty_updated_at' => now(),
        ])->save();
    }

    /**
     * Clear retry penalty state for a route after a successful provider request.
     */
    public function recordSuccess(RouteResult $route): void
    {
        $fallback = LaravelAiRouterFallback::query()->where('laravel_ai_router_model_id', $route->modelDbId)->first();

        if (! $fallback instanceof LaravelAiRouterFallback || (int) $fallback->penalty === 0) {
            return;
        }

        $fallback->forceFill([
            'penalty' => max(0, ((int) $fallback->penalty) - 1),
            'penalty_updated_at' => now(),
        ])->save();
    }

    /**
     * Mark the selected provider key invalid after an authentication failure.
     */
    public function recordAuthFailure(RouteResult $route): void
    {
        $key = LaravelAiRouterProviderKey::query()->find($route->keyId);

        if (! $key instanceof LaravelAiRouterProviderKey) {
            return;
        }

        $key->forceFill([
            'status' => 'invalid',
            'last_checked_at' => now(),
        ])->save();
    }

    /**
     * Route legacy fallback candidates for bootstrap/explicit non-selected strategies.
     *
     * @param  Collection<int, LaravelAiRouterFallback>  $fallbacks
     * @param  array<int, int>  $excludedKeyIds
     */
    private function routeFallbackPool(Collection $fallbacks, int $estimatedTokens, bool $requiresTools, array $excludedKeyIds): RouteResult
    {
        foreach ($this->candidateSelector->orderedFallbacks($fallbacks) as $fallback) {
            $model = LaravelAiRouterModel::query()
                ->whereKey($fallback->laravel_ai_router_model_id)
                ->where('enabled', true)
                ->first();

            if (! $model instanceof LaravelAiRouterModel || ! $this->adapters->has($model->platform)) {
                continue;
            }

            $key = $this->firstUsableKey($model, $estimatedTokens, $requiresTools, $excludedKeyIds);

            if (! $key instanceof LaravelAiRouterProviderKey) {
                continue;
            }

            return $this->toResult($model, $key);
        }

        throw new NoAvailableModelException('All Laravel AI Router models are exhausted. Add an enabled provider key or wait for limits to reset.');
    }

    /**
     * Determine whether a provider model cache has been initialized.
     */
    private function hasProviderModelCacheRows(): bool
    {
        return LaravelAiRouterProviderModelCache::query()->exists();
    }

    /**
     * Route auto requests through the user-selected provider-key/model cache pool.
     *
     * @param  array<int, int>  $excludedKeyIds
     */
    private function routeSelectedProviderPool(int $estimatedTokens, bool $requiresTools, array $excludedKeyIds): RouteResult
    {
        $cacheQuery = LaravelAiRouterProviderModelCache::query()
            ->with('providerKey')
            ->where('enabled', true)
            ->where('auto_enabled', true)
            ->whereHas('providerKey', function ($query): void {
                $query->where('enabled', true)
                    ->where('status', '!=', 'invalid')
                    ->where(function ($query): void {
                        $query->whereNull('models_cache_expires_at')
                            ->orWhere('models_cache_expires_at', '>=', now());
                    });
            })
            ->orderBy('provider_key_id')
            ->orderBy('model_id');

        if ($excludedKeyIds !== []) {
            $cacheQuery->whereNotIn('provider_key_id', $excludedKeyIds);
        }

        $providerGroups = $cacheQuery
            ->get()
            ->filter(fn (LaravelAiRouterProviderModelCache $cache): bool => $this->cacheRowIsAutoCompatible($cache))
            ->groupBy(fn (LaravelAiRouterProviderModelCache $cache): int => (int) $cache->provider_key_id)
            ->values();

        $randomizer = $this->randomizer();
        $shuffledProviderGroups = collect($randomizer->shuffleArray($providerGroups->all()));

        foreach ($shuffledProviderGroups as $providerGroup) {
            $shuffledCacheRows = $randomizer->shuffleArray($providerGroup->values()->all());

            foreach ($shuffledCacheRows as $cache) {
                $model = LaravelAiRouterModel::query()
                    ->where('platform', $cache->platform)
                    ->where('model_id', $cache->model_id)
                    ->where('enabled', true)
                    ->first();

                if (! $model instanceof LaravelAiRouterModel || ! $this->adapters->has($model->platform)) {
                    continue;
                }

                $fallbackExists = LaravelAiRouterFallback::query()
                    ->where('laravel_ai_router_model_id', $model->getKey())
                    ->where('enabled', true)
                    ->exists();

                if (! $fallbackExists) {
                    continue;
                }

                $key = $this->usableSelectedCacheKey($cache, $model, $estimatedTokens, $requiresTools, $excludedKeyIds);

                if (! $key instanceof LaravelAiRouterProviderKey) {
                    continue;
                }

                return $this->toResult($model, $key);
            }
        }

        throw new NoAvailableModelException('All selected Laravel AI Router provider models are exhausted. Select models for auto routing or wait for limits to reset.');
    }

    /**
     * Determine whether a selected cache row is still compatible with provider-specific auto chat routing.
     */
    private function cacheRowIsAutoCompatible(LaravelAiRouterProviderModelCache $cache): bool
    {
        try {
            $definition = ProviderCatalog::get((string) $cache->platform);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $this->modelAvailability->shouldEnableAutoFallback(
            (string) $cache->platform,
            $definition,
            $this->cacheRowPayload($cache),
        );
    }

    /**
     * Convert a cached provider-model row to the policy payload shape.
     *
     * @return array<string, mixed>
     */
    private function cacheRowPayload(LaravelAiRouterProviderModelCache $cache): array
    {
        return [
            'model_id' => (string) $cache->model_id,
            'display_name' => $cache->display_name,
            'context_window' => $cache->context_window,
            'budget_label' => $cache->budget_label,
            'supports_tools' => $cache->supports_tools,
            'is_free' => (bool) $cache->is_free,
            'raw_metadata' => $cache->raw_metadata,
        ];
    }

    /**
     * Return the selected cache row's provider key when every routing guard passes.
     *
     * @param  array<int, int>  $excludedKeyIds
     */
    private function usableSelectedCacheKey(LaravelAiRouterProviderModelCache $cache, LaravelAiRouterModel $model, int $estimatedTokens, bool $requiresTools, array $excludedKeyIds): ?LaravelAiRouterProviderKey
    {
        $key = $cache->providerKey;

        if (! $key instanceof LaravelAiRouterProviderKey) {
            return null;
        }

        if (in_array((int) $key->getKey(), $excludedKeyIds, true)) {
            return null;
        }

        if (! $key->enabled || $key->status === 'invalid') {
            return null;
        }

        if ($key->models_cache_expires_at !== null && $key->models_cache_expires_at->isPast()) {
            return null;
        }

        if (! $cache->enabled || ! $cache->auto_enabled) {
            return null;
        }

        if ($requiresTools && $cache->supports_tools === false) {
            return null;
        }

        if ($this->rateLimits->isOnCooldown($model->platform, $model->model_id, (int) $key->getKey())) {
            return null;
        }

        $limits = [
            'rpm' => $model->rpm_limit,
            'rpd' => $model->rpd_limit,
            'tpm' => $model->tpm_limit,
            'tpd' => $model->tpd_limit,
        ];

        if (! $this->rateLimits->canMakeRequest($model->platform, $model->model_id, (int) $key->getKey(), $limits)) {
            return null;
        }

        if (! $this->rateLimits->canUseTokens($model->platform, $model->model_id, (int) $key->getKey(), $estimatedTokens, $limits)) {
            return null;
        }

        return $key;
    }

    /**
     * Resolve and sanitize the configured auto-routing strategy.
     */
    private function autoStrategy(): string
    {
        $strategy = config('laravel-ai-router.routing.auto_strategy', 'random_provider');

        return in_array($strategy, ['priority', 'random', 'balanced_random', 'random_provider'], true)
            ? $strategy
            : 'random_provider';
    }

    /**
     * Use a deterministic randomizer only when tests explicitly provide a seed.
     */
    private function randomizer(): Randomizer
    {
        $seed = config('laravel-ai-router.routing.random_seed');

        if (is_int($seed) || (is_string($seed) && is_numeric($seed))) {
            return new Randomizer(new Mt19937((int) $seed));
        }

        return new Randomizer;
    }

    /**
     * Return the first enabled non-invalid provider key that is not currently in cooldown for the model.
     *
     * @param  array<int, int>  $excludedKeyIds
     */
    private function firstUsableKey(LaravelAiRouterModel $model, int $estimatedTokens, bool $requiresTools, array $excludedKeyIds): ?LaravelAiRouterProviderKey
    {
        $keys = LaravelAiRouterProviderKey::query()
            ->where('platform', $model->platform)
            ->where('enabled', true)
            ->where('status', '!=', 'invalid')
            ->orderBy('last_used_at')
            ->orderBy('id')
            ->get();

        foreach ($keys as $key) {
            if (in_array((int) $key->getKey(), $excludedKeyIds, true)) {
                continue;
            }

            if (! $this->keySupportsModel($key, $model, $requiresTools)) {
                continue;
            }

            if ($this->rateLimits->isOnCooldown($model->platform, $model->model_id, (int) $key->getKey())) {
                continue;
            }

            $limits = [
                'rpm' => $model->rpm_limit,
                'rpd' => $model->rpd_limit,
                'tpm' => $model->tpm_limit,
                'tpd' => $model->tpd_limit,
            ];

            if (! $this->rateLimits->canMakeRequest($model->platform, $model->model_id, (int) $key->getKey(), $limits)) {
                continue;
            }

            if (! $this->rateLimits->canUseTokens($model->platform, $model->model_id, (int) $key->getKey(), $estimatedTokens, $limits)) {
                continue;
            }

            return $key;
        }

        return null;
    }

    /**
     * Determine whether a provider key exposes a healthy non-expired cache row for the requested model.
     */
    private function keySupportsModel(LaravelAiRouterProviderKey $key, LaravelAiRouterModel $model, bool $requiresTools): bool
    {
        $hasCacheRows = LaravelAiRouterProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->exists();

        if (! $hasCacheRows) {
            return true;
        }

        if ($key->models_cache_expires_at !== null && $key->models_cache_expires_at->isPast()) {
            return false;
        }

        $cacheQuery = LaravelAiRouterProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->where('model_id', $model->model_id)
            ->where('enabled', true);

        if ($requiresTools) {
            $cacheQuery->where(function ($query): void {
                $query->where('supports_tools', true)
                    ->orWhereNull('supports_tools');
            });
        }

        return $cacheQuery->exists();
    }

    /**
     * Build the immutable route result object from a model row and provider key row.
     */
    private function toResult(LaravelAiRouterModel $model, LaravelAiRouterProviderKey $key): RouteResult
    {
        $key->forceFill(['last_used_at' => now()])->save();

        return new RouteResult(
            platform: $model->platform,
            modelId: $model->model_id,
            modelDbId: (int) $model->getKey(),
            displayName: $model->display_name,
            keyId: (int) $key->getKey(),
            apiKey: $key->credentialForProvider(),
            providerLabel: (string) $key->label,
        );
    }
}

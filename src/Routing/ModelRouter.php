<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Routing;

use Ferdiunal\LaravelAiRouter\Adapters\ProviderAdapterRegistry;
use Ferdiunal\LaravelAiRouter\Exceptions\ModelNotFoundException;
use Ferdiunal\LaravelAiRouter\Exceptions\NoAvailableModelException;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;

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

        $fallbacks = LaravelAiRouterFallback::query()
            ->where('enabled', true)
            ->orderByRaw('(priority + penalty) asc')
            ->orderBy('id')
            ->get();

        foreach ($fallbacks as $fallback) {
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
            ->where('enabled', true)
            ->where('is_free', true);

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
            apiKey: (string) $key->key,
            providerLabel: (string) $key->label,
        );
    }
}

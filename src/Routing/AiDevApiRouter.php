<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Routing;

use Ferdiunal\AiDevApi\Adapters\ProviderAdapterRegistry;
use Ferdiunal\AiDevApi\Exceptions\ModelNotFoundException;
use Ferdiunal\AiDevApi\Exceptions\NoAvailableModelException;
use Ferdiunal\AiDevApi\Models\AiDevApiFallback;
use Ferdiunal\AiDevApi\Models\AiDevApiModel;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderModelCache;

final class AiDevApiRouter
{
    public function __construct(
        private readonly ProviderAdapterRegistry $adapters,
        private readonly RateLimitWindowRepository $rateLimits,
    ) {}

    public function route(?string $modelId = 'auto', int $estimatedTokens = 1000): RouteResult
    {
        if ($modelId !== null && $modelId !== '' && $modelId !== 'auto') {
            $models = AiDevApiModel::query()
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

                $key = $this->firstUsableKey($model, $estimatedTokens);

                if ($key instanceof AiDevApiProviderKey) {
                    return $this->toResult($model, $key);
                }
            }

            throw new NoAvailableModelException("No enabled valid key is available for model [{$modelId}].");
        }

        $fallbacks = AiDevApiFallback::query()
            ->where('enabled', true)
            ->orderByRaw('(priority + penalty) asc')
            ->orderBy('id')
            ->get();

        foreach ($fallbacks as $fallback) {
            $model = AiDevApiModel::query()
                ->whereKey($fallback->ai_dev_api_model_id)
                ->where('enabled', true)
                ->first();

            if (! $model instanceof AiDevApiModel || ! $this->adapters->has($model->platform)) {
                continue;
            }

            $key = $this->firstUsableKey($model, $estimatedTokens);

            if (! $key instanceof AiDevApiProviderKey) {
                continue;
            }

            return $this->toResult($model, $key);
        }

        throw new NoAvailableModelException('All AI Dev API models are exhausted. Add an enabled provider key or wait for limits to reset.');
    }

    public function recordRetryableFailure(RouteResult $route): void
    {
        $fallback = AiDevApiFallback::query()->where('ai_dev_api_model_id', $route->modelDbId)->first();

        if (! $fallback instanceof AiDevApiFallback) {
            return;
        }

        $fallback->forceFill([
            'penalty' => min(
                (int) config('ai-dev-api.routing.max_penalty', 10),
                ((int) $fallback->penalty) + (int) config('ai-dev-api.routing.penalty_per_retryable_failure', 3),
            ),
            'penalty_updated_at' => now(),
        ])->save();
    }

    public function recordSuccess(RouteResult $route): void
    {
        $fallback = AiDevApiFallback::query()->where('ai_dev_api_model_id', $route->modelDbId)->first();

        if (! $fallback instanceof AiDevApiFallback || (int) $fallback->penalty === 0) {
            return;
        }

        $fallback->forceFill([
            'penalty' => max(0, ((int) $fallback->penalty) - 1),
            'penalty_updated_at' => now(),
        ])->save();
    }

    public function recordAuthFailure(RouteResult $route): void
    {
        $key = AiDevApiProviderKey::query()->find($route->keyId);

        if (! $key instanceof AiDevApiProviderKey) {
            return;
        }

        $key->forceFill([
            'status' => 'invalid',
            'last_checked_at' => now(),
        ])->save();
    }

    private function firstUsableKey(AiDevApiModel $model, int $estimatedTokens): ?AiDevApiProviderKey
    {
        $keys = AiDevApiProviderKey::query()
            ->where('platform', $model->platform)
            ->where('enabled', true)
            ->where('status', '!=', 'invalid')
            ->orderBy('last_used_at')
            ->orderBy('id')
            ->get();

        foreach ($keys as $key) {
            if (! $this->keySupportsModel($key, $model)) {
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

    private function keySupportsModel(AiDevApiProviderKey $key, AiDevApiModel $model): bool
    {
        $hasCacheRows = AiDevApiProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->exists();

        if (! $hasCacheRows) {
            return true;
        }

        if ($key->models_cache_expires_at !== null && $key->models_cache_expires_at->isPast()) {
            return false;
        }

        return AiDevApiProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->where('model_id', $model->model_id)
            ->where('enabled', true)
            ->where('is_free', true)
            ->exists();
    }

    private function toResult(AiDevApiModel $model, AiDevApiProviderKey $key): RouteResult
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

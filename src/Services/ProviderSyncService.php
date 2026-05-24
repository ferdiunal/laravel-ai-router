<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

use Ferdiunal\LaravelAiRouter\Adapters\ProviderAdapterRegistry;
use Ferdiunal\LaravelAiRouter\Exceptions\ProviderAuthenticationException;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Routing\RateLimitWindowRepository;
use Throwable;

/**
 * Validates provider credentials, refreshes model caches, and reports local quota readiness without leaking secrets.
 */
final class ProviderSyncService
{
    /**
     * Initialize the provider sync service with adapter, cache, and local rate-window collaborators.
     */
    public function __construct(
        private readonly ProviderAdapterRegistry $adapters,
        private readonly ProviderModelCacheService $modelCache,
        private readonly RateLimitWindowRepository $rateWindows,
    ) {}

    /**
     * Validate and optionally refresh one provider key, returning a stable secret-free result payload.
     */
    public function syncKey(LaravelAiRouterProviderKey $key, bool $refreshModels = true, bool $dryRun = false): ProviderSyncResult
    {
        $checkedAt = now();
        $modelsRefreshed = false;
        $status = 'error';
        $message = 'Provider adapter is not routable.';

        if (! $this->adapters->has((string) $key->platform)) {
            return $this->result($key, $status, $modelsRefreshed, $checkedAt->toDateTimeString(), $message);
        }

        try {
            $isValid = $this->adapters->for((string) $key->platform)->validateKey($key->credentialForProvider());
        } catch (ProviderAuthenticationException) {
            $isValid = false;
        } catch (Throwable $exception) {
            return $this->result(
                $key,
                'error',
                $modelsRefreshed,
                $checkedAt->toDateTimeString(),
                $this->safeMessage('Credential validation failed', $exception),
            );
        }

        if (! $isValid) {
            if (! $dryRun) {
                $this->markInvalid($key, $checkedAt);
                $this->disableCacheRows($key);
                $key->refresh();
            }

            return $this->result($key, 'invalid', false, $checkedAt->toDateTimeString(), 'Provider credential validation failed.');
        }

        if (! $dryRun) {
            $key->forceFill([
                'status' => 'healthy',
                'last_checked_at' => $checkedAt,
            ])->save();
            $key->refresh();
        }

        if ($refreshModels && ! $dryRun) {
            $this->modelCache->refreshForKey($key);
            $modelsRefreshed = true;
            $key->refresh();

            if ($key->status === 'invalid') {
                return $this->result($key, 'invalid', $modelsRefreshed, $checkedAt->toDateTimeString(), 'Provider model refresh invalidated the credential.');
            }
        }

        $quota = $this->quotaSnapshot($key);
        $status = (bool) data_get($quota, 'blocked', false) ? 'rate_limited' : 'healthy';
        $message = $status === 'rate_limited'
            ? 'All selected auto-routing models are blocked by local quota windows or cooldowns.'
            : 'Provider credential is valid.';

        return $this->result($key, $status, $modelsRefreshed, $checkedAt->toDateTimeString(), $message, $quota);
    }

    /**
     * Return selected auto-model local quota snapshots for one provider key.
     *
     * @return array<string, mixed>
     */
    private function quotaSnapshot(LaravelAiRouterProviderKey $key): array
    {
        $models = $this->selectedAutoModels($key);
        $snapshots = collect($models)
            ->map(fn (LaravelAiRouterProviderModelCache $model): array => $this->rateWindows->quotaSnapshot(
                (string) $model->platform,
                (string) $model->model_id,
                (int) $model->provider_key_id,
                [
                    'rpm' => $model->rpm_limit,
                    'rpd' => $model->rpd_limit,
                    'tpm' => $model->tpm_limit,
                    'tpd' => $model->tpd_limit,
                ],
            ))
            ->values()
            ->all();

        return [
            'source' => 'local_estimate',
            'blocked' => $snapshots !== [] && collect($snapshots)->every(fn (array $snapshot): bool => (bool) $snapshot['blocked']),
            'models' => $snapshots,
        ];
    }

    /**
     * Return enabled selected cache rows that participate in provider-key scoped auto routing.
     *
     * @return array<int, LaravelAiRouterProviderModelCache>
     */
    private function selectedAutoModels(LaravelAiRouterProviderKey $key): array
    {
        return LaravelAiRouterProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->where('enabled', true)
            ->where('auto_enabled', true)
            ->orderBy('model_id')
            ->get()
            ->all();
    }

    /**
     * Build the command result with fresh counts from storage.
     *
     * @param  array<string, mixed>|null  $quota
     */
    private function result(LaravelAiRouterProviderKey $key, string $status, bool $modelsRefreshed, string $checkedAt, string $message, ?array $quota = null): ProviderSyncResult
    {
        $key->refresh();
        $quota ??= $this->quotaSnapshot($key);

        return new ProviderSyncResult(
            keyId: (int) $key->getKey(),
            platform: (string) $key->platform,
            label: (string) $key->label,
            enabled: (bool) $key->enabled,
            apiStatus: $status,
            modelsRefreshed: $modelsRefreshed,
            cachedModelCount: $this->modelCache->cachedCountForKey($key),
            selectedAutoModelCount: count($this->selectedAutoModels($key)),
            quota: $quota,
            checkedAt: $checkedAt,
            message: $message,
        );
    }

    /**
     * Mark a provider key as invalid without exposing credential values.
     */
    private function markInvalid(LaravelAiRouterProviderKey $key, mixed $checkedAt): void
    {
        $key->forceFill([
            'status' => 'invalid',
            'last_checked_at' => $checkedAt,
        ])->save();
    }

    /**
     * Disable stale cache rows for credentials known to be invalid.
     */
    private function disableCacheRows(LaravelAiRouterProviderKey $key): void
    {
        LaravelAiRouterProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->update(['enabled' => false]);
    }

    /**
     * Return a short secret-free validation error message.
     */
    private function safeMessage(string $prefix, Throwable $exception): string
    {
        return $prefix.' ('.class_basename($exception).').';
    }
}

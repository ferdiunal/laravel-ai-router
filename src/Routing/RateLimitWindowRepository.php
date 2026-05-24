<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Routing;

use Carbon\CarbonImmutable;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterRateWindow;
use Illuminate\Support\Facades\DB;

/**
 * Persists local request windows, token windows, and cooldown windows for provider/model/key routing decisions.
 */
final class RateLimitWindowRepository
{
    /**
     * Determine whether request-rate windows allow another request for the provider/model/key tuple.
     *
     * @param  array{rpm:?int,rpd:?int,tpm:?int,tpd:?int}  $limits
     */
    public function canMakeRequest(string $platform, string $modelId, int $keyId, array $limits): bool
    {
        return $this->underRequestLimit($platform, $modelId, $keyId, 'rpm', $limits['rpm'] ?? null)
            && $this->underRequestLimit($platform, $modelId, $keyId, 'rpd', $limits['rpd'] ?? null);
    }

    /**
     * Determine whether token-rate windows allow the estimated token usage for the provider/model/key tuple.
     *
     * @param  array{tpm:?int,tpd:?int}  $limits
     */
    public function canUseTokens(string $platform, string $modelId, int $keyId, int $estimatedTokens, array $limits): bool
    {
        return $this->underTokenLimit($platform, $modelId, $keyId, 'tpm', $estimatedTokens, $limits['tpm'] ?? null)
            && $this->underTokenLimit($platform, $modelId, $keyId, 'tpd', $estimatedTokens, $limits['tpd'] ?? null);
    }

    /**
     * Increment the local request window for the provider/model/key tuple.
     */
    public function recordRequest(string $platform, string $modelId, int $keyId): void
    {
        $this->incrementWindow($platform, $modelId, $keyId, 'rpm', 1, 0);
        $this->incrementWindow($platform, $modelId, $keyId, 'rpd', 1, 0);
    }

    /**
     * Increment the local token window for the provider/model/key tuple.
     */
    public function recordTokens(string $platform, string $modelId, int $keyId, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $this->incrementWindow($platform, $modelId, $keyId, 'tpm', 0, $tokens);
        $this->incrementWindow($platform, $modelId, $keyId, 'tpd', 0, $tokens);
    }

    /**
     * Persist a cooldown window for the provider/model/key tuple after a retryable failure.
     */
    public function setCooldown(string $platform, string $modelId, int $keyId, int $seconds): void
    {
        LaravelAiRouterRateWindow::query()->create([
            'platform' => $platform,
            'model_id' => $modelId,
            'provider_key_id' => $keyId,
            'window_type' => 'cooldown',
            'window_starts_at' => now(),
            'window_ends_at' => now()->addSeconds($seconds),
            'cooldown_until' => now()->addSeconds($seconds),
        ]);
    }

    /**
     * Determine whether the provider/model/key tuple is currently blocked by a cooldown window.
     */
    public function isOnCooldown(string $platform, string $modelId, int $keyId): bool
    {
        return LaravelAiRouterRateWindow::query()
            ->where('platform', $platform)
            ->where('model_id', $modelId)
            ->where('provider_key_id', $keyId)
            ->where('window_type', 'cooldown')
            ->where('cooldown_until', '>', now())
            ->exists();
    }

    /**
     * Return a read-only local quota snapshot for the provider/model/key tuple.
     *
     * @param  array{rpm:?int,rpd:?int,tpm:?int,tpd:?int}  $limits
     * @return array<string, mixed>
     */
    public function quotaSnapshot(string $platform, string $modelId, int $keyId, array $limits): array
    {
        $cooldownUntil = $this->cooldownUntil($platform, $modelId, $keyId);
        $limitSnapshots = [];

        foreach (['rpm', 'rpd', 'tpm', 'tpd'] as $type) {
            $limit = $limits[$type] ?? null;
            $used = $this->currentWindowUsage($platform, $modelId, $keyId, $type);
            $remaining = $limit !== null && $limit > 0 ? max(0, $limit - $used) : null;
            $window = $this->windowBounds($type);

            $limitSnapshots[$type] = [
                'limit' => $limit,
                'used' => $used,
                'remaining' => $remaining,
                'window_ends_at' => $window['end']->toDateTimeString(),
            ];
        }

        $blocked = $cooldownUntil !== null
            || collect($limitSnapshots)->contains(fn (array $snapshot): bool => $snapshot['remaining'] === 0);

        return [
            'provider' => $platform,
            'model_id' => $modelId,
            'provider_key_id' => $keyId,
            'blocked' => $blocked,
            'cooldown_until' => $cooldownUntil,
            'limits' => $limitSnapshots,
        ];
    }

    /**
     * Compare a rate-window row against the configured request limit.
     */
    private function underRequestLimit(string $platform, string $modelId, int $keyId, string $type, ?int $limit): bool
    {
        if ($limit === null || $limit <= 0) {
            return true;
        }

        $used = $this->currentWindowUsage($platform, $modelId, $keyId, $type);

        return $used < $limit;
    }

    /**
     * Compare a rate-window row against the configured token limit.
     */
    private function underTokenLimit(string $platform, string $modelId, int $keyId, string $type, int $estimatedTokens, ?int $limit): bool
    {
        if ($limit === null || $limit <= 0) {
            return true;
        }

        $used = $this->currentWindowUsage($platform, $modelId, $keyId, $type);

        return $used + $estimatedTokens <= $limit;
    }

    /**
     * Return the active cooldown timestamp for the provider/model/key tuple, if present.
     */
    private function cooldownUntil(string $platform, string $modelId, int $keyId): ?string
    {
        $cooldownUntil = LaravelAiRouterRateWindow::query()
            ->where('platform', $platform)
            ->where('model_id', $modelId)
            ->where('provider_key_id', $keyId)
            ->where('window_type', 'cooldown')
            ->where('cooldown_until', '>', now())
            ->max('cooldown_until');

        return is_string($cooldownUntil) ? $cooldownUntil : null;
    }

    /**
     * Return current request or token usage for a bounded local rate window.
     */
    private function currentWindowUsage(string $platform, string $modelId, int $keyId, string $type): int
    {
        $window = $this->windowBounds($type);
        $column = in_array($type, ['rpm', 'rpd'], true) ? 'request_count' : 'token_count';

        return (int) LaravelAiRouterRateWindow::query()
            ->where('platform', $platform)
            ->where('model_id', $modelId)
            ->where('provider_key_id', $keyId)
            ->where('window_type', $type)
            ->where('window_starts_at', $window['start'])
            ->value($column);
    }

    /**
     * Create or increment a bounded rate-window row for local routing controls.
     */
    private function incrementWindow(string $platform, string $modelId, int $keyId, string $type, int $requests, int $tokens): void
    {
        $window = $this->windowBounds($type);

        DB::connection(config('laravel-ai-router.database.connection') ?: 'laravel-ai-router')->transaction(function () use ($platform, $modelId, $keyId, $type, $requests, $tokens, $window): void {
            $row = LaravelAiRouterRateWindow::query()
                ->where('platform', $platform)
                ->where('model_id', $modelId)
                ->where('provider_key_id', $keyId)
                ->where('window_type', $type)
                ->where('window_starts_at', $window['start'])
                ->lockForUpdate()
                ->first();

            if (! $row instanceof LaravelAiRouterRateWindow) {
                LaravelAiRouterRateWindow::query()->create([
                    'platform' => $platform,
                    'model_id' => $modelId,
                    'provider_key_id' => $keyId,
                    'window_type' => $type,
                    'window_starts_at' => $window['start'],
                    'window_ends_at' => $window['end'],
                    'request_count' => $requests,
                    'token_count' => $tokens,
                ]);

                return;
            }

            $row->forceFill([
                'request_count' => ((int) $row->request_count) + $requests,
                'token_count' => ((int) $row->token_count) + $tokens,
            ])->save();
        });
    }

    /**
     * Calculate the active rolling rate-limit window boundaries for the requested window type.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function windowBounds(string $type): array
    {
        $now = CarbonImmutable::now();

        return match ($type) {
            'rpm', 'tpm' => ['start' => $now->startOfMinute(), 'end' => $now->startOfMinute()->addMinute()],
            'rpd', 'tpd' => ['start' => $now->startOfDay(), 'end' => $now->startOfDay()->addDay()],
            default => ['start' => $now, 'end' => $now],
        };
    }
}

<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterRateWindow;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterRequest;
use Illuminate\Support\Facades\DB;

/**
 * Prunes package-owned usage analytics and routing rate-window rows according to retention config.
 */
final class StoragePruner
{
    /**
     * Delete usage and rate-window rows older than the configured retention cutoffs.
     *
     * @return array{usage: int, rate_windows: int}
     */
    public function prune(): array
    {
        return [
            'usage' => LaravelAiRouterRequest::query()
                ->where('created_at', '<', now()->subDays($this->retentionDays('usage.retention_days', 30)))
                ->delete(),
            'rate_windows' => LaravelAiRouterRateWindow::query()
                ->where('window_ends_at', '<', now()->subDays($this->retentionDays('rate_windows.retention_days', 7)))
                ->delete(),
        ];
    }

    /**
     * Run SQLite VACUUM explicitly after pruning when the package connection uses SQLite.
     */
    public function vacuum(): bool
    {
        $connection = (string) (config('laravel-ai-router.database.connection') ?: 'laravel-ai-router');
        $database = config("database.connections.{$connection}.database");

        if (config("database.connections.{$connection}.driver") !== 'sqlite' || $database === ':memory:') {
            return false;
        }

        DB::connection($connection)->statement('VACUUM');

        return true;
    }

    /**
     * Read and clamp a retention-days setting from package config.
     */
    private function retentionDays(string $key, int $default): int
    {
        return max(1, (int) config("laravel-ai-router.{$key}", $default));
    }
}

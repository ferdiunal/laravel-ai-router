<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterRateWindow;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterRequest;
use Ferdiunal\LaravelAiRouter\Services\StoragePruner;
use Ferdiunal\LaravelAiRouter\Tests\TestCase;

function migrateLaravelAiRouterForStoragePruningTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

function createLaravelAiRouterUsageRow(string $requestId, string $createdAt): void
{
    LaravelAiRouterRequest::query()->create([
        'request_id' => $requestId,
        'platform' => 'openrouter',
        'provider_label' => 'Primary',
        'model_id' => 'qwen/qwen3-coder:free',
        'status' => 'success',
        'input_tokens' => 1,
        'output_tokens' => 1,
        'total_tokens' => 2,
        'latency_ms' => 10,
        'attempt' => 1,
        'created_at' => $createdAt,
    ]);
}

function createLaravelAiRouterRateWindow(string $modelId, string $windowEndsAt): void
{
    LaravelAiRouterRateWindow::query()->create([
        'platform' => 'openrouter',
        'model_id' => $modelId,
        'window_type' => 'rpm',
        'window_starts_at' => now()->subHour(),
        'window_ends_at' => $windowEndsAt,
        'request_count' => 1,
        'token_count' => 0,
        'created_at' => $windowEndsAt,
        'updated_at' => $windowEndsAt,
    ]);
}

it('prunes usage and rate-window rows older than configured retention cutoffs', function () {
    migrateLaravelAiRouterForStoragePruningTests();

    config()->set('laravel-ai-router.usage.retention_days', 30);
    config()->set('laravel-ai-router.rate_windows.retention_days', 7);

    createLaravelAiRouterUsageRow('old-usage', now()->subDays(45)->toDateTimeString());
    createLaravelAiRouterUsageRow('recent-usage', now()->subDays(3)->toDateTimeString());
    createLaravelAiRouterRateWindow('old-rate', now()->subDays(10)->toDateTimeString());
    createLaravelAiRouterRateWindow('recent-rate', now()->subDays(2)->toDateTimeString());

    $result = app(StoragePruner::class)->prune();

    expect($result)->toBe(['usage' => 1, 'rate_windows' => 1])
        ->and(LaravelAiRouterRequest::query()->pluck('request_id')->all())->toBe(['recent-usage'])
        ->and(LaravelAiRouterRateWindow::query()->pluck('model_id')->all())->toBe(['recent-rate']);
});

it('registers an artisan prune command for package storage maintenance', function () {
    /** @var TestCase $this */
    migrateLaravelAiRouterForStoragePruningTests();

    config()->set('laravel-ai-router.usage.retention_days', 30);
    config()->set('laravel-ai-router.rate_windows.retention_days', 7);

    createLaravelAiRouterUsageRow('old-usage', now()->subDays(45)->toDateTimeString());
    createLaravelAiRouterRateWindow('old-rate', now()->subDays(10)->toDateTimeString());

    $this->artisan('laravel-ai-router:prune')
        ->expectsOutputToContain('Pruned 1 usage rows and 1 rate-window rows.')
        ->assertSuccessful();

    expect(LaravelAiRouterRequest::query()->count())->toBe(0)
        ->and(LaravelAiRouterRateWindow::query()->count())->toBe(0);
});

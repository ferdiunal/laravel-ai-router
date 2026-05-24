<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Illuminate\Support\Facades\Schema;

function migrateLaravelAiRouterBeforeAutoEnabledSelectionBackfill(): void
{
    foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $migrationFile) {
        if (str_contains($migrationFile, '2026_01_01_000011_add_auto_enabled_to_laravel_ai_router_provider_model_caches_table')) {
            continue;
        }

        $migration = include $migrationFile;
        $migration->up();
    }
}

function runAutoEnabledSelectionBackfillMigration(): void
{
    $migrationFile = __DIR__.'/../../../database/migrations/2026_01_01_000011_add_auto_enabled_to_laravel_ai_router_provider_model_caches_table.php';

    expect(file_exists($migrationFile))->toBeTrue();

    $migration = include $migrationFile;
    $migration->up();
}

function createAutoEnabledBackfillModel(string $platform, string $modelId, bool $enabled = true): LaravelAiRouterModel
{
    return LaravelAiRouterModel::query()->create([
        'platform' => $platform,
        'model_id' => $modelId,
        'display_name' => $modelId,
        'intelligence_rank' => 10,
        'speed_rank' => 10,
        'enabled' => $enabled,
    ]);
}

function createAutoEnabledBackfillKey(string $platform, string $label, string $status = 'healthy', bool $enabled = true, bool $expired = false): LaravelAiRouterProviderKey
{
    return LaravelAiRouterProviderKey::query()->create([
        'platform' => $platform,
        'label' => $label,
        'key' => 'key-'.$platform.'-'.strtolower($label).'-value-123456',
        'status' => $status,
        'enabled' => $enabled,
        'models_cached_at' => now(),
        'models_cache_expires_at' => $expired ? now()->subMinute() : now()->addHour(),
    ]);
}

function createAutoEnabledBackfillCache(LaravelAiRouterProviderKey $key, string $modelId, bool $enabled = true): LaravelAiRouterProviderModelCache
{
    return LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => $key->platform,
        'provider_label' => $key->label,
        'model_id' => $modelId,
        'display_name' => $modelId,
        'is_free' => true,
        'enabled' => $enabled,
        'source' => 'live',
        'checked_at' => now(),
    ]);
}

it('adds auto selection column and backfills only existing enabled fallback cached models', function (): void {
    migrateLaravelAiRouterBeforeAutoEnabledSelectionBackfill();

    $selectedModel = createAutoEnabledBackfillModel('openrouter', 'selected-model');
    $fallbackDisabledModel = createAutoEnabledBackfillModel('openrouter', 'fallback-disabled-model');
    $invalidKeyModel = createAutoEnabledBackfillModel('openrouter', 'invalid-key-model');
    $expiredKeyModel = createAutoEnabledBackfillModel('openrouter', 'expired-key-model');
    $disabledCacheModel = createAutoEnabledBackfillModel('openrouter', 'disabled-cache-model');

    LaravelAiRouterFallback::query()->create(['laravel_ai_router_model_id' => $selectedModel->getKey(), 'priority' => 1, 'enabled' => true, 'penalty' => 0]);
    LaravelAiRouterFallback::query()->create(['laravel_ai_router_model_id' => $fallbackDisabledModel->getKey(), 'priority' => 2, 'enabled' => false, 'penalty' => 0]);
    LaravelAiRouterFallback::query()->create(['laravel_ai_router_model_id' => $invalidKeyModel->getKey(), 'priority' => 3, 'enabled' => true, 'penalty' => 0]);
    LaravelAiRouterFallback::query()->create(['laravel_ai_router_model_id' => $expiredKeyModel->getKey(), 'priority' => 4, 'enabled' => true, 'penalty' => 0]);
    LaravelAiRouterFallback::query()->create(['laravel_ai_router_model_id' => $disabledCacheModel->getKey(), 'priority' => 5, 'enabled' => true, 'penalty' => 0]);

    $healthy = createAutoEnabledBackfillKey('openrouter', 'Healthy');
    $invalid = createAutoEnabledBackfillKey('openrouter', 'Invalid', status: 'invalid');
    $expired = createAutoEnabledBackfillKey('openrouter', 'Expired', expired: true);

    createAutoEnabledBackfillCache($healthy, 'selected-model');
    createAutoEnabledBackfillCache($healthy, 'fallback-disabled-model');
    createAutoEnabledBackfillCache($invalid, 'invalid-key-model');
    createAutoEnabledBackfillCache($expired, 'expired-key-model');
    createAutoEnabledBackfillCache($healthy, 'disabled-cache-model', enabled: false);

    runAutoEnabledSelectionBackfillMigration();

    expect(Schema::connection('laravel-ai-router')->hasColumn('laravel_ai_router_provider_model_caches', 'auto_enabled'))->toBeTrue()
        ->and(LaravelAiRouterProviderModelCache::query()->where('model_id', 'selected-model')->value('auto_enabled'))->toBeTrue()
        ->and(LaravelAiRouterProviderModelCache::query()->where('model_id', 'fallback-disabled-model')->value('auto_enabled'))->toBeFalse()
        ->and(LaravelAiRouterProviderModelCache::query()->where('model_id', 'invalid-key-model')->value('auto_enabled'))->toBeFalse()
        ->and(LaravelAiRouterProviderModelCache::query()->where('model_id', 'expired-key-model')->value('auto_enabled'))->toBeFalse()
        ->and(LaravelAiRouterProviderModelCache::query()->where('model_id', 'disabled-cache-model')->value('auto_enabled'))->toBeFalse();
});

it('does not fail when provider model cache table is missing', function (): void {
    runAutoEnabledSelectionBackfillMigration();

    expect(Schema::connection('laravel-ai-router')->hasTable('laravel_ai_router_provider_model_caches'))->toBeFalse();
});

<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\LaravelAiRouterProvider;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Services\ProviderKeyManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelCacheService;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;

function migrateLaravelAiRouterForCacheTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('caches supported free models by provider and label when a key is added', function () {
    migrateLaravelAiRouterForCacheTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                ['id' => 'qwen/qwen3-coder:free', 'name' => 'Qwen3 Coder', 'context_length' => 262144, 'supported_parameters' => ['tools']],
                ['id' => 'paid/model', 'name' => 'Paid Model'],
            ],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('openrouter', 'key-openrouter-value-123456', 'Primary', refreshModels: true);

    expect($key)->toBeInstanceOf(LaravelAiRouterProviderKey::class)
        ->and($key->platform)->toBe('openrouter')
        ->and($key->label)->toBe('Primary')
        ->and(LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->pluck('model_id')->all())
        ->toBe(['qwen/qwen3-coder:free']);

    $provider = app(AiManager::class)->textProvider('laravel-ai-router');

    expect($provider)->toBeInstanceOf(LaravelAiRouterProvider::class);
    assert($provider instanceof LaravelAiRouterProvider);
    expect($provider->models('openrouter', 'Primary'))->toBe(['auto', 'qwen/qwen3-coder:free']);
});

it('falls back to curated models when a provider cannot return a live model list', function () {
    migrateLaravelAiRouterForCacheTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(['error' => ['message' => 'offline']], 503),
    ]);

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Fallback',
        'key' => 'key-openrouter-value-abcdef',
        'status' => 'unknown',
        'enabled' => true,
    ]);

    app(ProviderModelCacheService::class)->refreshForKey($key);

    expect(LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->where('source', 'curated')->count())
        ->toBeGreaterThan(0);
    expect($key->refresh()->status)->toBe('unknown');
});

it('marks provider keys invalid on model refresh auth failures without falling back to curated cache', function () {
    migrateLaravelAiRouterForCacheTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(['error' => ['message' => 'invalid api key']], 401),
    ]);

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Invalid',
        'key' => 'key-openrouter-value-invalid',
        'status' => 'unknown',
        'enabled' => true,
    ]);

    $rows = app(ProviderModelCacheService::class)->refreshForKey($key);

    expect($rows)->toBe([]);
    expect($key->refresh()->status)->toBe('invalid');
    expect($key->last_checked_at)->not->toBeNull();
    expect(LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->where('enabled', true)->count())->toBe(0);
});

it('does not cache models for providers without a routable adapter', function () {
    migrateLaravelAiRouterForCacheTests();

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'google',
        'label' => 'Unsupported',
        'key' => 'key-google-value-123456',
        'status' => 'unknown',
        'enabled' => true,
    ]);

    expect(app(ProviderModelCacheService::class)->refreshForKey($key))->toBe([]);
    expect(LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->count())->toBe(0);
});

it('excludes expired provider label model cache rows from model listings', function () {
    migrateLaravelAiRouterForCacheTests();

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => 'key-openrouter-value-expired',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now()->subDays(2),
        'models_cache_expires_at' => now()->subMinute(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => 'openrouter',
        'provider_label' => 'Primary',
        'model_id' => 'expired/model:free',
        'display_name' => 'Expired Model',
        'is_free' => true,
        'enabled' => true,
        'source' => 'live',
        'checked_at' => now()->subDays(2),
    ]);

    $provider = app(AiManager::class)->textProvider('laravel-ai-router');
    $modelCache = app(ProviderModelCacheService::class);

    expect($provider)->toBeInstanceOf(LaravelAiRouterProvider::class);
    assert($provider instanceof LaravelAiRouterProvider);
    expect($provider->models('openrouter', 'Primary', includeAuto: false))->toBe([]);
    expect($modelCache->cachedModelsForKey($key))->toBe([]);
    expect($modelCache->choicesForKey($key))->toBe(['auto' => 'Auto — route requests across healthy cached free models']);
});

it('exposes cached model choices only for routable healthy provider keys', function () {
    migrateLaravelAiRouterForCacheTests();

    $makeKeyWithCache = function (string $platform, string $label, string $status = 'healthy', bool $enabled = true): LaravelAiRouterProviderKey {
        $key = LaravelAiRouterProviderKey::query()->create([
            'platform' => $platform,
            'label' => $label,
            'key' => 'key-'.$platform.'-'.$label.'-value-123456',
            'status' => $status,
            'enabled' => $enabled,
            'models_cached_at' => now(),
            'models_cache_expires_at' => now()->addHour(),
        ]);

        LaravelAiRouterProviderModelCache::query()->create([
            'provider_key_id' => $key->getKey(),
            'platform' => $platform,
            'provider_label' => $label,
            'model_id' => strtolower($label).'/model:free',
            'display_name' => $label.' Model',
            'is_free' => true,
            'enabled' => true,
            'source' => 'live',
            'checked_at' => now(),
        ]);

        return $key;
    };

    $service = app(ProviderModelCacheService::class);
    $usable = $makeKeyWithCache('openrouter', 'Usable');
    $invalid = $makeKeyWithCache('openrouter', 'Invalid', status: 'invalid');
    $disabled = $makeKeyWithCache('openrouter', 'Disabled', enabled: false);
    $unsupported = $makeKeyWithCache('google', 'Unsupported');

    expect($service->cachedModelsForKey($usable))->toHaveCount(1);
    expect($service->choicesForKey($usable))->toHaveKey('usable/model:free');

    foreach ([$invalid, $disabled, $unsupported] as $key) {
        expect($service->cachedModelsForKey($key))->toBe([]);
        expect($service->choicesForKey($key))->toBe(['auto' => 'Auto — route requests across healthy cached free models']);
    }
});

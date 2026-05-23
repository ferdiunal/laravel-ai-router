<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\AiDevApiProvider;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderModelCache;
use Ferdiunal\AiDevApi\Services\ProviderKeyManager;
use Ferdiunal\AiDevApi\Services\ProviderModelCacheService;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;

function migrateAiDevApiForCacheTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php.stub') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('caches supported free models by provider and label when a key is added', function () {
    migrateAiDevApiForCacheTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                ['id' => 'qwen/qwen3-coder:free', 'name' => 'Qwen3 Coder', 'context_length' => 262144, 'supported_parameters' => ['tools']],
                ['id' => 'paid/model', 'name' => 'Paid Model'],
            ],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('openrouter', 'key-openrouter-value-123456', 'Primary', refreshModels: true);

    expect($key)->toBeInstanceOf(AiDevApiProviderKey::class)
        ->and($key->platform)->toBe('openrouter')
        ->and($key->label)->toBe('Primary')
        ->and(AiDevApiProviderModelCache::query()->where('provider_key_id', $key->getKey())->pluck('model_id')->all())
        ->toBe(['qwen/qwen3-coder:free']);

    $provider = app(AiManager::class)->textProvider('ai-dev-api');

    expect($provider)->toBeInstanceOf(AiDevApiProvider::class);
    assert($provider instanceof AiDevApiProvider);
    expect($provider->models('openrouter', 'Primary'))->toBe(['auto', 'qwen/qwen3-coder:free']);
});

it('falls back to curated models when a provider cannot return a live model list', function () {
    migrateAiDevApiForCacheTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(['error' => ['message' => 'offline']], 503),
    ]);

    $key = AiDevApiProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Fallback',
        'key' => 'key-openrouter-value-abcdef',
        'status' => 'unknown',
        'enabled' => true,
    ]);

    app(ProviderModelCacheService::class)->refreshForKey($key);

    expect(AiDevApiProviderModelCache::query()->where('provider_key_id', $key->getKey())->where('source', 'curated')->count())
        ->toBeGreaterThan(0);
    expect($key->refresh()->status)->toBe('unknown');
});

it('marks provider keys invalid on model refresh auth failures without falling back to curated cache', function () {
    migrateAiDevApiForCacheTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(['error' => ['message' => 'invalid api key']], 401),
    ]);

    $key = AiDevApiProviderKey::query()->create([
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
    expect(AiDevApiProviderModelCache::query()->where('provider_key_id', $key->getKey())->where('enabled', true)->count())->toBe(0);
});

it('excludes expired provider label model cache rows from model listings', function () {
    migrateAiDevApiForCacheTests();

    $key = AiDevApiProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => 'key-openrouter-value-expired',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now()->subDays(2),
        'models_cache_expires_at' => now()->subMinute(),
    ]);

    AiDevApiProviderModelCache::query()->create([
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

    $provider = app(AiManager::class)->textProvider('ai-dev-api');

    expect($provider)->toBeInstanceOf(AiDevApiProvider::class);
    assert($provider instanceof AiDevApiProvider);
    expect($provider->models('openrouter', 'Primary', includeAuto: false))->toBe([]);
});

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
});

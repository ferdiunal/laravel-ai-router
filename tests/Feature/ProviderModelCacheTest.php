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

it('caches available live models by provider and label while preserving free metadata', function () {
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
        ->and(LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->orderBy('model_id')->pluck('model_id')->all())
        ->toBe(['paid/model', 'qwen/qwen3-coder:free']);

    $paid = LaravelAiRouterProviderModelCache::query()->where('model_id', 'paid/model')->firstOrFail();
    expect($paid->is_free)->toBeFalse()
        ->and($paid->budget_label)->toBe('credits-based');

    $provider = app(AiManager::class)->textProvider('laravel-ai-router');

    expect($provider)->toBeInstanceOf(LaravelAiRouterProvider::class);
    assert($provider instanceof LaravelAiRouterProvider);
    expect($provider->models('openrouter', 'Primary'))->toBe(['auto', 'paid/model', 'qwen/qwen3-coder:free']);
});

it('caches nvidia live models as free credit-backed available models', function () {
    migrateLaravelAiRouterForCacheTests();

    Http::fake([
        'https://integrate.api.nvidia.com/v1/models' => Http::response([
            'data' => [
                [
                    'id' => 'meta/llama-3.1-70b-instruct',
                    'name' => 'Llama 3.1 70B Instruct',
                    'context_length' => 131072,
                    'supported_parameters' => ['tools'],
                ],
                [
                    'id' => 'nvidia/nemotron-nano-9b-v2',
                    'name' => 'Nemotron Nano 9B v2',
                    'context_length' => 128000,
                ],
            ],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('nvidia', 'key-nvidia-value-123456', 'NVIDIA', refreshModels: true);

    $models = LaravelAiRouterProviderModelCache::query()
        ->where('provider_key_id', $key->getKey())
        ->orderBy('model_id')
        ->get();

    expect($models->pluck('model_id')->all())->toBe([
        'meta/llama-3.1-70b-instruct',
        'nvidia/nemotron-nano-9b-v2',
    ]);

    expect($models->every(fn (LaravelAiRouterProviderModelCache $model): bool => $model->is_free === true))->toBeTrue();
    expect($models->every(fn (LaravelAiRouterProviderModelCache $model): bool => $model->budget_label === 'credits-based'))->toBeTrue();
    expect($models->firstWhere('model_id', 'meta/llama-3.1-70b-instruct')->supports_tools)->toBeTrue();
    expect(app(ProviderModelCacheService::class)->modelIds('nvidia', 'NVIDIA', includeAuto: false))->toBe([
        'meta/llama-3.1-70b-instruct',
        'nvidia/nemotron-nano-9b-v2',
    ]);

    expect(app(ProviderModelCacheService::class)->choicesForKey($key)['meta/llama-3.1-70b-instruct'])
        ->toContain('free')
        ->toContain('credits-based');
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

it('caches google live Gemini models through the native adapter', function () {
    migrateLaravelAiRouterForCacheTests();

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models?key=*' => Http::response([
            'models' => [[
                'name' => 'models/gemini-2.5-flash',
                'displayName' => 'Gemini 2.5 Flash',
                'inputTokenLimit' => 1048576,
                'supportedGenerationMethods' => ['generateContent', 'streamGenerateContent'],
            ], [
                'name' => 'models/embedding-001',
                'displayName' => 'Embedding',
                'supportedGenerationMethods' => ['embedContent'],
            ]],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('google', 'key-google-value-123456', 'Google', refreshModels: true);

    $models = LaravelAiRouterProviderModelCache::query()
        ->where('provider_key_id', $key->getKey())
        ->orderBy('model_id')
        ->get();

    expect($models->pluck('model_id')->all())->toBe(['gemini-2.5-flash']);
    expect($models->first()->context_window)->toBe(1048576);
    expect($models->first()->supports_tools)->toBeNull();
    expect(app(ProviderModelCacheService::class)->modelIds('google', 'Google', includeAuto: false))->toBe(['gemini-2.5-flash']);
});

it('refreshes cloudflare models with stored account metadata and token-only bearer auth', function () {
    migrateLaravelAiRouterForCacheTests();

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/account-123/ai/models/search' => Http::response([
            'result' => [[
                'name' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
                'display_name' => 'Llama 3.3 70B fp8-fast',
                'properties' => ['context_window' => 131072],
            ]],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add(
        'cloudflare',
        'cf-token-secret-123456',
        'Workers',
        refreshModels: true,
        credentialMetadata: ['account_id' => 'account-123'],
    );

    expect($key->key)->toBe('cf-token-secret-123456')
        ->and($key->credential_metadata)->toBe(['account_id' => 'account-123'])
        ->and(LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->pluck('model_id')->all())
        ->toBe(['@cf/meta/llama-3.3-70b-instruct-fp8-fast']);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/account-123/ai/models/search'
        && $request->hasHeader('Authorization', 'Bearer cf-token-secret-123456'));
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
    expect($modelCache->choicesForKey($key))->toBe(['auto' => 'Auto — route requests across healthy cached available models']);
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
    $unsupported = $makeKeyWithCache('legacy-unsupported', 'Unsupported');

    expect($service->cachedModelsForKey($usable))->toHaveCount(1);
    expect($service->choicesForKey($usable))->toHaveKey('usable/model:free');

    foreach ([$invalid, $disabled, $unsupported] as $key) {
        expect($service->cachedModelsForKey($key))->toBe([]);
        expect($service->choicesForKey($key))->toBe(['auto' => 'Auto — route requests across healthy cached available models']);
    }
});

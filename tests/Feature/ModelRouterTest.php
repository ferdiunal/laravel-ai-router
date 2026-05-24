<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Catalog\SeedModelCatalog;
use Ferdiunal\LaravelAiRouter\Exceptions\ModelNotFoundException;
use Ferdiunal\LaravelAiRouter\Exceptions\NoAvailableModelException;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Routing\ModelRouter;
use Ferdiunal\LaravelAiRouter\Services\ProviderKeyManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelCacheService;
use Illuminate\Support\Facades\Http;

function migrateLaravelAiRouterForRouterTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('auto routes to the first enabled fallback with an enabled non-invalid key', function () {
    migrateLaravelAiRouterForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = LaravelAiRouterModel::query()->where('platform', 'openrouter')->firstOrFail();
    LaravelAiRouterProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Primary',
        'key' => 'key-openrouter-value-123456',
        'status' => 'healthy',
        'enabled' => true,
    ]);

    $route = app(ModelRouter::class)->route('auto');

    expect($route->platform)->toBe('openrouter')
        ->and($route->modelId)->toBe($model->model_id)
        ->and($route->apiKey)->toBe('key-openrouter-value-123456');
});

it('composes cloudflare route credentials from separate account metadata and encrypted token', function () {
    migrateLaravelAiRouterForRouterTests();

    $model = LaravelAiRouterModel::query()->create([
        'platform' => 'cloudflare',
        'model_id' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'display_name' => 'Llama 3.3 70B fp8-fast',
        'intelligence_rank' => 9,
        'speed_rank' => 11,
        'enabled' => true,
    ]);

    LaravelAiRouterProviderKey::query()->create([
        'platform' => 'cloudflare',
        'label' => 'Workers',
        'key' => 'cf-token-secret-123456',
        'credential_metadata' => ['account_id' => 'account-123'],
        'status' => 'healthy',
        'enabled' => true,
    ]);

    $route = app(ModelRouter::class)->route('@cf/meta/llama-3.3-70b-instruct-fp8-fast');

    expect($route->platform)->toBe('cloudflare')
        ->and($route->modelId)->toBe($model->model_id)
        ->and($route->apiKey)->toBe('account-123:cf-token-secret-123456');
});

it('skips disabled and invalid provider keys', function () {
    migrateLaravelAiRouterForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = LaravelAiRouterModel::query()->where('platform', 'openrouter')->firstOrFail();
    LaravelAiRouterProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Invalid',
        'key' => 'key-invalid-value-123456',
        'status' => 'invalid',
        'enabled' => true,
    ]);
    LaravelAiRouterProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Disabled',
        'key' => 'key-disabled-value-123456',
        'status' => 'healthy',
        'enabled' => false,
    ]);
    LaravelAiRouterProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Healthy',
        'key' => 'key-healthy-value-123456',
        'status' => 'healthy',
        'enabled' => true,
    ]);

    $route = app(ModelRouter::class)->route($model->model_id);

    expect($route->apiKey)->toBe('key-healthy-value-123456');
});

it('does not route through expired provider model cache rows', function () {
    migrateLaravelAiRouterForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = LaravelAiRouterModel::query()
        ->where('platform', 'openrouter')
        ->where('model_id', 'qwen/qwen3-coder:free')
        ->firstOrFail();

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Expired Cache',
        'key' => 'key-expired-cache-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now()->subDays(2),
        'models_cache_expires_at' => now()->subMinute(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => $model->platform,
        'provider_label' => 'Expired Cache',
        'model_id' => $model->model_id,
        'display_name' => $model->display_name,
        'is_free' => true,
        'enabled' => true,
        'source' => 'live',
        'checked_at' => now()->subDays(2),
    ]);

    app(ModelRouter::class)->route($model->model_id);
})->throws(NoAvailableModelException::class, 'No enabled valid key is available');

it('does not route tool prompts through cached models that do not support tools', function () {
    migrateLaravelAiRouterForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = LaravelAiRouterModel::query()
        ->where('platform', 'openrouter')
        ->where('model_id', 'qwen/qwen3-coder:free')
        ->firstOrFail();

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'No Tools',
        'key' => 'key-no-tools-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => $model->platform,
        'provider_label' => 'No Tools',
        'model_id' => $model->model_id,
        'display_name' => $model->display_name,
        'is_free' => true,
        'supports_tools' => false,
        'enabled' => true,
        'source' => 'live',
        'checked_at' => now(),
    ]);

    app(ModelRouter::class)->route($model->model_id, 1000, requiresTools: true);
})->throws(NoAvailableModelException::class, 'No enabled valid key is available');

it('routes tool prompts through cached models with unknown tool support', function () {
    migrateLaravelAiRouterForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = LaravelAiRouterModel::query()
        ->where('platform', 'openrouter')
        ->where('model_id', 'qwen/qwen3-coder:free')
        ->firstOrFail();

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Unknown Tools',
        'key' => 'key-unknown-tools-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => $model->platform,
        'provider_label' => 'Unknown Tools',
        'model_id' => $model->model_id,
        'display_name' => $model->display_name,
        'is_free' => true,
        'supports_tools' => null,
        'enabled' => true,
        'source' => 'live',
        'checked_at' => now(),
    ]);

    $route = app(ModelRouter::class)->route($model->model_id, 1000, requiresTools: true);

    expect($route->apiKey)->toBe('key-unknown-tools-value-123456');
});

it('routes exact nvidia live cached models without enabling them for auto fallback by default', function () {
    migrateLaravelAiRouterForRouterTests();

    Http::fake([
        'https://integrate.api.nvidia.com/v1/models' => Http::response([
            'data' => [
                ['id' => 'nvidia/nemotron-nano-9b-v2', 'name' => 'Nemotron Nano 9B v2'],
            ],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('nvidia', 'key-nvidia-value-123456', 'NVIDIA', refreshModels: true);

    $model = LaravelAiRouterModel::query()
        ->where('platform', 'nvidia')
        ->where('model_id', 'nvidia/nemotron-nano-9b-v2')
        ->first();

    expect($model)->not->toBeNull();
    assert($model instanceof LaravelAiRouterModel);
    expect($model->enabled)->toBeTrue();

    $fallback = LaravelAiRouterFallback::query()
        ->where('laravel_ai_router_model_id', $model->getKey())
        ->first();

    expect($fallback)->not->toBeNull();
    assert($fallback instanceof LaravelAiRouterFallback);
    expect($fallback->enabled)->toBeFalse();
    expect(app(ProviderModelCacheService::class)->firstAvailableModelId())->toBeNull();

    $route = app(ModelRouter::class)->route('nvidia/nemotron-nano-9b-v2');

    expect($route->platform)->toBe('nvidia')
        ->and($route->modelId)->toBe('nvidia/nemotron-nano-9b-v2')
        ->and($route->keyId)->toBe($key->getKey());
});

it('keeps seeded nvidia live free models out of auto fallback when refreshed', function () {
    migrateLaravelAiRouterForRouterTests();
    app(SeedModelCatalog::class)->seed();

    Http::fake([
        'https://integrate.api.nvidia.com/v1/models' => Http::response([
            'data' => [
                ['id' => 'meta/llama-3.1-70b-instruct', 'name' => 'Llama 3.1 70B Instruct'],
            ],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('nvidia', 'key-nvidia-value-123456', 'NVIDIA', refreshModels: true);

    $model = LaravelAiRouterModel::query()
        ->where('platform', 'nvidia')
        ->where('model_id', 'meta/llama-3.1-70b-instruct')
        ->firstOrFail();

    $fallback = LaravelAiRouterFallback::query()
        ->where('laravel_ai_router_model_id', $model->getKey())
        ->firstOrFail();

    expect($model->enabled)->toBeTrue();
    expect($fallback->enabled)->toBeFalse();
    expect(app(ProviderModelCacheService::class)->cachedModelsForKey($key)[0]->is_free)->toBeTrue();
});

it('routes exact non-free live cached models for other providers without adding them to auto fallback', function () {
    migrateLaravelAiRouterForRouterTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                ['id' => 'provider/paid-model', 'name' => 'Provider Paid Model'],
            ],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('openrouter', 'key-openrouter-value-123456', 'Primary', refreshModels: true);

    $model = LaravelAiRouterModel::query()
        ->where('platform', 'openrouter')
        ->where('model_id', 'provider/paid-model')
        ->first();

    expect($model)->not->toBeNull();
    assert($model instanceof LaravelAiRouterModel);

    $fallback = LaravelAiRouterFallback::query()
        ->where('laravel_ai_router_model_id', $model->getKey())
        ->first();

    expect($fallback)->not->toBeNull();
    assert($fallback instanceof LaravelAiRouterFallback);
    expect($fallback->enabled)->toBeFalse();

    $route = app(ModelRouter::class)->route('provider/paid-model');

    expect($route->platform)->toBe('openrouter')
        ->and($route->modelId)->toBe('provider/paid-model')
        ->and($route->keyId)->toBe($key->getKey());
});

it('fails clearly for unknown specific model ids', function () {
    migrateLaravelAiRouterForRouterTests();
    app(SeedModelCatalog::class)->seed();

    app(ModelRouter::class)->route('missing-model');
})->throws(ModelNotFoundException::class, "Model 'missing-model' is not in the enabled Laravel AI Router catalog.");

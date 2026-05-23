<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Catalog\SeedModelCatalog;
use Ferdiunal\AiDevApi\Exceptions\ModelNotFoundException;
use Ferdiunal\AiDevApi\Exceptions\NoAvailableModelException;
use Ferdiunal\AiDevApi\Models\AiDevApiModel;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderModelCache;
use Ferdiunal\AiDevApi\Routing\AiDevApiRouter;

function migrateAiDevApiForRouterTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('auto routes to the first enabled fallback with an enabled non-invalid key', function () {
    migrateAiDevApiForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = AiDevApiModel::query()->where('platform', 'openrouter')->firstOrFail();
    AiDevApiProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Primary',
        'key' => 'key-openrouter-value-123456',
        'status' => 'healthy',
        'enabled' => true,
    ]);

    $route = app(AiDevApiRouter::class)->route('auto');

    expect($route->platform)->toBe('openrouter')
        ->and($route->modelId)->toBe($model->model_id)
        ->and($route->apiKey)->toBe('key-openrouter-value-123456');
});

it('skips disabled and invalid provider keys', function () {
    migrateAiDevApiForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = AiDevApiModel::query()->where('platform', 'openrouter')->firstOrFail();
    AiDevApiProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Invalid',
        'key' => 'key-invalid-value-123456',
        'status' => 'invalid',
        'enabled' => true,
    ]);
    AiDevApiProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Disabled',
        'key' => 'key-disabled-value-123456',
        'status' => 'healthy',
        'enabled' => false,
    ]);
    AiDevApiProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Healthy',
        'key' => 'key-healthy-value-123456',
        'status' => 'healthy',
        'enabled' => true,
    ]);

    $route = app(AiDevApiRouter::class)->route($model->model_id);

    expect($route->apiKey)->toBe('key-healthy-value-123456');
});

it('does not route through expired provider model cache rows', function () {
    migrateAiDevApiForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = AiDevApiModel::query()
        ->where('platform', 'openrouter')
        ->where('model_id', 'qwen/qwen3-coder:free')
        ->firstOrFail();

    $key = AiDevApiProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Expired Cache',
        'key' => 'key-expired-cache-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now()->subDays(2),
        'models_cache_expires_at' => now()->subMinute(),
    ]);

    AiDevApiProviderModelCache::query()->create([
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

    app(AiDevApiRouter::class)->route($model->model_id);
})->throws(NoAvailableModelException::class, 'No enabled valid key is available');

it('does not route tool prompts through cached models that do not support tools', function () {
    migrateAiDevApiForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = AiDevApiModel::query()
        ->where('platform', 'openrouter')
        ->where('model_id', 'qwen/qwen3-coder:free')
        ->firstOrFail();

    $key = AiDevApiProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'No Tools',
        'key' => 'key-no-tools-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    AiDevApiProviderModelCache::query()->create([
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

    app(AiDevApiRouter::class)->route($model->model_id, 1000, requiresTools: true);
})->throws(NoAvailableModelException::class, 'No enabled valid key is available');

it('routes tool prompts through cached models with unknown tool support', function () {
    migrateAiDevApiForRouterTests();
    app(SeedModelCatalog::class)->seed();

    $model = AiDevApiModel::query()
        ->where('platform', 'openrouter')
        ->where('model_id', 'qwen/qwen3-coder:free')
        ->firstOrFail();

    $key = AiDevApiProviderKey::query()->create([
        'platform' => $model->platform,
        'label' => 'Unknown Tools',
        'key' => 'key-unknown-tools-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    AiDevApiProviderModelCache::query()->create([
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

    $route = app(AiDevApiRouter::class)->route($model->model_id, 1000, requiresTools: true);

    expect($route->apiKey)->toBe('key-unknown-tools-value-123456');
});

it('fails clearly for unknown specific model ids', function () {
    migrateAiDevApiForRouterTests();
    app(SeedModelCatalog::class)->seed();

    app(AiDevApiRouter::class)->route('missing-model');
})->throws(ModelNotFoundException::class, "Model 'missing-model' is not in the enabled AI Dev API catalog.");

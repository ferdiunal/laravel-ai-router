<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Catalog\SeedModelCatalog;
use Ferdiunal\AiDevApi\Exceptions\ModelNotFoundException;
use Ferdiunal\AiDevApi\Models\AiDevApiModel;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Routing\AiDevApiRouter;

function migrateAiDevApiForRouterTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php.stub') as $migrationFile) {
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

it('fails clearly for unknown specific model ids', function () {
    migrateAiDevApiForRouterTests();
    app(SeedModelCatalog::class)->seed();

    app(AiDevApiRouter::class)->route('missing-model');
})->throws(ModelNotFoundException::class, "Model 'missing-model' is not in the enabled AI Dev API catalog.");

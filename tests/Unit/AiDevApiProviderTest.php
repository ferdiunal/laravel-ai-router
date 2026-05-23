<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\AiDevApiProvider;
use Ferdiunal\AiDevApi\Services\ModelPreferenceManager;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;

function migrateAiDevApiForProviderUnitTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('uses the root AiDevApiProvider as the text provider class', function () {
    expect(class_exists(AiDevApiProvider::class))->toBeTrue();

    config()->set('ai.providers.ai-dev-api', ['driver' => 'ai-dev-api']);

    $provider = app(AiManager::class)->textProvider('ai-dev-api');

    expect($provider)->toBeInstanceOf(AiDevApiProvider::class);
    expect($provider)->toBeInstanceOf(TextProvider::class);

    assert($provider instanceof AiDevApiProvider);
    expect($provider->driver())->toBe('ai-dev-api');
    expect($provider->defaultTextModel())->toBe('auto');
});

it('uses a stored default text model preference before config auto fallback', function () {
    migrateAiDevApiForProviderUnitTests();
    app(ModelPreferenceManager::class)->setDefaultTextModel('qwen/qwen3-coder:free');

    config()->set('ai.providers.ai-dev-api', ['driver' => 'ai-dev-api']);
    $provider = app(AiManager::class)->textProvider('ai-dev-api');

    assert($provider instanceof AiDevApiProvider);
    expect($provider->defaultTextModel())->toBe('qwen/qwen3-coder:free');
});

it('falls back to auto when the model preference table is not installed yet', function () {
    DB::connection((config('ai-dev-api.database.connection') ?: 'ai-dev-api'))->getSchemaBuilder()->dropAllTables();

    config()->set('ai.providers.ai-dev-api', ['driver' => 'ai-dev-api']);
    $provider = app(AiManager::class)->textProvider('ai-dev-api');

    assert($provider instanceof AiDevApiProvider);
    expect($provider->defaultTextModel())->toBe('auto');
});

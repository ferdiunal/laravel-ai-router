<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\LaravelAiRouterProvider;
use Ferdiunal\LaravelAiRouter\Services\ModelPreferenceManager;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;

function migrateLaravelAiRouterForProviderUnitTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('uses the root LaravelAiRouterProvider as the text provider class', function () {
    expect(class_exists(LaravelAiRouterProvider::class))->toBeTrue();

    config()->set('ai.providers.laravel-ai-router', ['driver' => 'laravel-ai-router']);

    $provider = app(AiManager::class)->textProvider('laravel-ai-router');

    expect($provider)->toBeInstanceOf(LaravelAiRouterProvider::class);
    expect($provider)->toBeInstanceOf(TextProvider::class);

    assert($provider instanceof LaravelAiRouterProvider);
    expect($provider->driver())->toBe('laravel-ai-router');
    expect($provider->defaultTextModel())->toBe('auto');
});

it('uses a stored default text model preference before config auto fallback', function () {
    migrateLaravelAiRouterForProviderUnitTests();
    app(ModelPreferenceManager::class)->setDefaultTextModel('qwen/qwen3-coder:free');

    config()->set('ai.providers.laravel-ai-router', ['driver' => 'laravel-ai-router']);
    $provider = app(AiManager::class)->textProvider('laravel-ai-router');

    assert($provider instanceof LaravelAiRouterProvider);
    expect($provider->defaultTextModel())->toBe('qwen/qwen3-coder:free');
});

it('falls back to auto when the model preference table is not installed yet', function () {
    DB::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'))->getSchemaBuilder()->dropAllTables();

    config()->set('ai.providers.laravel-ai-router', ['driver' => 'laravel-ai-router']);
    $provider = app(AiManager::class)->textProvider('laravel-ai-router');

    assert($provider instanceof LaravelAiRouterProvider);
    expect($provider->defaultTextModel())->toBe('auto');
});

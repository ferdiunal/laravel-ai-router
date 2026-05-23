<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\AiDevApiProvider;
use Ferdiunal\AiDevApi\AiDevApiServiceProvider;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\AiManager;

it('merges package config and registers no routes', function () {
    expect(config('ai-dev-api.driver'))->toBe('ai-dev-api');
    expect(config('ai-dev-api.routing.max_attempts'))->toBe(20);
    expect(count(Route::getRoutes()->getRoutes()))->toBe(0);
});

it('registers the ai-dev-api driver with Laravel AI manager', function () {
    config()->set('ai.providers.ai-dev-api', ['driver' => 'ai-dev-api']);
    config()->set('ai.default', 'ai-dev-api');

    $provider = app(AiManager::class)->textProvider('ai-dev-api');

    assert($provider instanceof AiDevApiProvider);
    expect($provider->defaultTextModel())->toBe('auto');
    expect($provider->driver())->toBe('ai-dev-api');
    expect($provider->name())->toBe('ai-dev-api');
});

it('publishes config only and keeps package migrations internal', function () {
    expect(AiDevApiServiceProvider::pathsToPublish(AiDevApiServiceProvider::class, 'ai-dev-api-config'))
        ->not->toBeEmpty();

    expect(AiDevApiServiceProvider::pathsToPublish(AiDevApiServiceProvider::class, 'ai-dev-api-migrations'))
        ->toBe([]);
});

it('registers a dedicated ai-dev-api sqlite connection by default', function () {
    expect(config('ai-dev-api.database.connection'))->toBe('ai-dev-api');
    expect(config('ai-dev-api.database.sqlite.database'))->toEndWith('database/ai-dev-api.sqlite');
    expect(config('database.connections.ai-dev-api.driver'))->toBe('sqlite');
    expect(config('database.connections.ai-dev-api.foreign_key_constraints'))->toBeTrue();
});

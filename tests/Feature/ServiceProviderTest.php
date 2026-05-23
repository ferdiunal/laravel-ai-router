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

it('publishes config and migrations only through the service provider', function () {
    $provider = new AiDevApiServiceProvider(app());

    expect($provider)->toBeInstanceOf(AiDevApiServiceProvider::class);
});

it('publishes migration stubs as runnable php migration files', function () {
    $paths = AiDevApiServiceProvider::pathsToPublish(AiDevApiServiceProvider::class, 'ai-dev-api-migrations');

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $source => $target) {
        expect($source)->toEndWith('.php.stub');
        expect($target)->toEndWith('.php');
        expect($target)->not->toEndWith('.stub');
    }
});

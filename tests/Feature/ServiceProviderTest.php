<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\LaravelAiRouterProvider;
use Ferdiunal\LaravelAiRouter\LaravelAiRouterServiceProvider;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\AiManager;

it('merges package config and registers no routes', function () {
    expect(config('laravel-ai-router.driver'))->toBe('laravel-ai-router');
    expect(config('laravel-ai-router.routing.max_attempts'))->toBe(20);
    expect(count(Route::getRoutes()->getRoutes()))->toBe(0);
});

it('registers the laravel-ai-router driver with Laravel AI manager', function () {
    config()->set('ai.providers.laravel-ai-router', ['driver' => 'laravel-ai-router']);
    config()->set('ai.default', 'laravel-ai-router');

    $provider = app(AiManager::class)->textProvider('laravel-ai-router');

    assert($provider instanceof LaravelAiRouterProvider);
    expect($provider->defaultTextModel())->toBe('auto');
    expect($provider->driver())->toBe('laravel-ai-router');
    expect($provider->name())->toBe('laravel-ai-router');
});

it('publishes config only and keeps package migrations internal', function () {
    expect(LaravelAiRouterServiceProvider::pathsToPublish(LaravelAiRouterServiceProvider::class, 'laravel-ai-router-config'))
        ->not->toBeEmpty();

    expect(LaravelAiRouterServiceProvider::pathsToPublish(LaravelAiRouterServiceProvider::class, 'laravel-ai-router-migrations'))
        ->toBe([]);
});

it('registers a dedicated laravel-ai-router sqlite connection by default', function () {
    expect(config('laravel-ai-router.database.connection'))->toBe('laravel-ai-router');
    expect(config('laravel-ai-router.database.sqlite.database'))->toEndWith('database/laravel-ai-router.sqlite');
    expect(config('database.connections.laravel-ai-router.driver'))->toBe('sqlite');
    expect(config('database.connections.laravel-ai-router.foreign_key_constraints'))->toBeTrue();
});

<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('prepares hidden sqlite storage and internal migrations without option flags', function () {
    /** @var TestCase $this */
    $database = storage_path('framework/testing/laravel-ai-router-install.sqlite');
    if (file_exists($database)) {
        unlink($database);
    }

    config()->set('laravel-ai-router.database.connection', 'laravel-ai-router');
    config()->set('laravel-ai-router.database.sqlite.database', $database);
    config()->set('database.connections.laravel-ai-router', [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('laravel-ai-router');

    $this->artisan('laravel-ai-router:install')
        ->expectsOutputToContain('Laravel AI Router install flow completed.')
        ->assertSuccessful();

    expect(file_exists($database))->toBeTrue();
    expect(Schema::connection('laravel-ai-router')->hasTable('laravel_ai_router_models'))->toBeTrue();
    expect(Schema::connection('laravel-ai-router')->hasTable('laravel_ai_router_provider_keys'))->toBeTrue();
    expect(Schema::connection('laravel-ai-router')->hasTable('laravel_ai_router_provider_model_caches'))->toBeTrue();
    expect(LaravelAiRouterModel::query()->count())->toBeGreaterThan(0);
});

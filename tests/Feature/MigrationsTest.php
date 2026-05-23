<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

function migrateLaravelAiRouterForMigrationTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('creates the sqlite compatible laravel ai router tables on the package connection', function () {
    migrateLaravelAiRouterForMigrationTests();

    $schema = Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'));

    expect($schema->hasTable('laravel_ai_router_models'))->toBeTrue()
        ->and($schema->hasTable('laravel_ai_router_provider_keys'))->toBeTrue()
        ->and($schema->hasTable('laravel_ai_router_fallbacks'))->toBeTrue()
        ->and($schema->hasTable('laravel_ai_router_requests'))->toBeTrue()
        ->and($schema->hasTable('laravel_ai_router_rate_windows'))->toBeTrue()
        ->and($schema->hasTable('laravel_ai_router_provider_model_caches'))->toBeTrue()
        ->and($schema->hasTable('laravel_ai_router_provider_definitions'))->toBeTrue()
        ->and($schema->hasTable('laravel_ai_router_settings'))->toBeTrue();
});

it('keeps package migrations idempotent when tables already exist', function () {
    migrateLaravelAiRouterForMigrationTests();
    migrateLaravelAiRouterForMigrationTests();

    expect(Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'))->hasTable('laravel_ai_router_models'))->toBeTrue();
});

it('uses explicit short index names for portable package migrations', function () {
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migration) {
        $contents = file_get_contents($migration);

        preg_match_all("/(?:index|unique)\([^;]+?'([^']+)'/", $contents, $matches);

        foreach ($matches[1] as $indexName) {
            expect(strlen($indexName))->toBeLessThanOrEqual(64);
        }
    }
});

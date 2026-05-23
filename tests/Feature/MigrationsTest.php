<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

function migrateAiDevApiForMigrationTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('creates the sqlite compatible ai dev api tables on the package connection', function () {
    migrateAiDevApiForMigrationTests();

    $schema = Schema::connection((config('ai-dev-api.database.connection') ?: 'ai-dev-api'));

    expect($schema->hasTable('ai_dev_api_models'))->toBeTrue()
        ->and($schema->hasTable('ai_dev_api_provider_keys'))->toBeTrue()
        ->and($schema->hasTable('ai_dev_api_fallbacks'))->toBeTrue()
        ->and($schema->hasTable('ai_dev_api_requests'))->toBeTrue()
        ->and($schema->hasTable('ai_dev_api_rate_windows'))->toBeTrue()
        ->and($schema->hasTable('ai_dev_api_provider_model_caches'))->toBeTrue()
        ->and($schema->hasTable('ai_dev_api_provider_definitions'))->toBeTrue()
        ->and($schema->hasTable('ai_dev_api_settings'))->toBeTrue();
});

it('keeps package migrations idempotent when tables already exist', function () {
    migrateAiDevApiForMigrationTests();
    migrateAiDevApiForMigrationTests();

    expect(Schema::connection((config('ai-dev-api.database.connection') ?: 'ai-dev-api'))->hasTable('ai_dev_api_models'))->toBeTrue();
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

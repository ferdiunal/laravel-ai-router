<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the sqlite compatible ai dev api tables', function () {
    foreach (glob(__DIR__.'/../../database/migrations/*.php.stub') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }

    expect(Schema::hasTable('ai_dev_api_models'))->toBeTrue()
        ->and(Schema::hasTable('ai_dev_api_provider_keys'))->toBeTrue()
        ->and(Schema::hasTable('ai_dev_api_fallbacks'))->toBeTrue()
        ->and(Schema::hasTable('ai_dev_api_requests'))->toBeTrue()
        ->and(Schema::hasTable('ai_dev_api_rate_windows'))->toBeTrue()
        ->and(Schema::hasTable('ai_dev_api_provider_model_caches'))->toBeTrue()
        ->and(Schema::hasTable('ai_dev_api_settings'))->toBeTrue();
});

it('uses explicit short index names for portable package migrations', function () {
    foreach (glob(__DIR__.'/../../database/migrations/*.php.stub') as $migration) {
        $contents = file_get_contents($migration);

        preg_match_all("/(?:index|unique)\([^;]+?'([^']+)'/", $contents, $matches);

        foreach ($matches[1] as $indexName) {
            expect(strlen($indexName))->toBeLessThanOrEqual(64);
        }
    }
});

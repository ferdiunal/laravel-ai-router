<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Models\AiDevApiModel;
use Ferdiunal\AiDevApi\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('prepares hidden sqlite storage and internal migrations without option flags', function () {
    /** @var TestCase $this */
    $database = storage_path('framework/testing/ai-dev-api-install.sqlite');
    if (file_exists($database)) {
        unlink($database);
    }

    config()->set('ai-dev-api.database.connection', 'ai-dev-api');
    config()->set('ai-dev-api.database.sqlite.database', $database);
    config()->set('database.connections.ai-dev-api', [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('ai-dev-api');

    $this->artisan('ai-dev-api:install')
        ->expectsOutputToContain('AI Dev API install flow completed.')
        ->assertSuccessful();

    expect(file_exists($database))->toBeTrue();
    expect(Schema::connection('ai-dev-api')->hasTable('ai_dev_api_models'))->toBeTrue();
    expect(Schema::connection('ai-dev-api')->hasTable('ai_dev_api_provider_keys'))->toBeTrue();
    expect(Schema::connection('ai-dev-api')->hasTable('ai_dev_api_provider_model_caches'))->toBeTrue();
    expect(AiDevApiModel::query()->count())->toBeGreaterThan(0);
});

<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Catalog\ModelCatalog;
use Ferdiunal\AiDevApi\Catalog\SeedModelCatalog;
use Ferdiunal\AiDevApi\Models\AiDevApiFallback;
use Ferdiunal\AiDevApi\Models\AiDevApiModel;

function migrateAiDevApiForCatalogTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php.stub') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('seeds the model catalog and initializes fallback order idempotently', function () {
    migrateAiDevApiForCatalogTests();

    app(SeedModelCatalog::class)->seed();
    app(SeedModelCatalog::class)->seed();

    expect(AiDevApiModel::query()->count())->toBe(count(ModelCatalog::all()))
        ->and(AiDevApiFallback::query()->count())->toBe(count(ModelCatalog::all()))
        ->and(AiDevApiFallback::query()->orderBy('priority')->pluck('priority')->all())->toBe(range(1, count(ModelCatalog::all())));
});

it('preserves an existing fallback priority while adding missing model rows', function () {
    migrateAiDevApiForCatalogTests();

    app(SeedModelCatalog::class)->seed();

    $first = AiDevApiFallback::query()->orderBy('priority')->firstOrFail();
    $first->forceFill(['priority' => 99])->save();

    app(SeedModelCatalog::class)->seed();

    expect($first->refresh()->priority)->toBe(99);
});

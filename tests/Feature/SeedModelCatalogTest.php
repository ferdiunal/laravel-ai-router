<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Catalog\ModelCatalog;
use Ferdiunal\LaravelAiRouter\Catalog\SeedModelCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;

function migrateLaravelAiRouterForCatalogTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('seeds the model catalog and initializes fallback order idempotently', function () {
    migrateLaravelAiRouterForCatalogTests();

    app(SeedModelCatalog::class)->seed();
    app(SeedModelCatalog::class)->seed();

    expect(LaravelAiRouterModel::query()->count())->toBe(count(ModelCatalog::all()))
        ->and(LaravelAiRouterFallback::query()->count())->toBe(count(ModelCatalog::all()))
        ->and(LaravelAiRouterFallback::query()->orderBy('priority')->pluck('priority')->all())->toBe(range(1, count(ModelCatalog::all())));
});

it('preserves an existing fallback priority while adding missing model rows', function () {
    migrateLaravelAiRouterForCatalogTests();

    app(SeedModelCatalog::class)->seed();

    $first = LaravelAiRouterFallback::query()->orderBy('priority')->firstOrFail();
    $first->forceFill(['priority' => 99])->save();

    app(SeedModelCatalog::class)->seed();

    expect($first->refresh()->priority)->toBe(99);
});

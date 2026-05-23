<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Adapters\ProviderAdapterRegistry;
use Ferdiunal\LaravelAiRouter\Catalog\ModelCatalog;
use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;

it('seeds only models for registered platforms', function () {
    $platforms = array_keys(ProviderCatalog::all());

    foreach (ModelCatalog::all() as $model) {
        expect($platforms)->toContain($model['platform']);
    }
});

it('does not ship enabled model rows for non-routable provider platforms', function () {
    $registry = app(ProviderAdapterRegistry::class);

    foreach (ModelCatalog::all() as $model) {
        expect($registry->has((string) $model['platform']))
            ->toBeTrue("Model [{$model['model_id']}] belongs to non-routable platform [{$model['platform']}].");
    }
});

it('contains the virtual auto model as provider default but not as a persisted catalog row', function () {
    expect(collect(ModelCatalog::all())->pluck('model_id')->contains('auto'))->toBeFalse();
});

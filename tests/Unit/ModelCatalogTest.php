<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Catalog\ModelCatalog;
use Ferdiunal\AiDevApi\Catalog\ProviderCatalog;

it('seeds only models for registered platforms', function () {
    $platforms = array_keys(ProviderCatalog::all());

    foreach (ModelCatalog::all() as $model) {
        expect($platforms)->toContain($model['platform']);
    }
});

it('contains the virtual auto model as provider default but not as a persisted catalog row', function () {
    expect(collect(ModelCatalog::all())->pluck('model_id')->contains('auto'))->toBeFalse();
});

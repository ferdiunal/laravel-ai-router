<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\AiDevApiProvider;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;

it('uses the root AiDevApiProvider as the text provider class', function () {
    expect(class_exists(AiDevApiProvider::class))->toBeTrue();

    config()->set('ai.providers.ai-dev-api', ['driver' => 'ai-dev-api']);

    $provider = app(AiManager::class)->textProvider('ai-dev-api');

    expect($provider)->toBeInstanceOf(AiDevApiProvider::class);
    expect($provider)->toBeInstanceOf(TextProvider::class);

    assert($provider instanceof AiDevApiProvider);
    expect($provider->driver())->toBe('ai-dev-api');
    expect($provider->defaultTextModel())->toBe('auto');
});

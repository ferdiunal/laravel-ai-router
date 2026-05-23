<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Adapters\ProviderAdapterRegistry;
use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;

it('contains the active laravel-ai-router provider platforms', function () {
    expect(array_keys(ProviderCatalog::all()))->toBe([
        'cohere',
        'groq',
        'cerebras',
        'sambanova',
        'nvidia',
        'mistral',
        'openrouter',
        'github',
        'zhipu',
        'ollama',
        'kilo',
        'pollinations',
        'llm7',
    ]);
});

it('only exposes built-in providers with routable adapters', function () {
    $registry = app(ProviderAdapterRegistry::class);

    foreach (ProviderCatalog::builtIn() as $platform => $definition) {
        expect($registry->has($platform))
            ->toBeTrue("Built-in provider [{$platform}] with adapter [{$definition['adapter']}] must be routable.");
    }
});

it('marks anonymous providers as requiring placeholder key rows', function () {
    expect(ProviderCatalog::get('pollinations')['requires_placeholder_key'])->toBeTrue()
        ->and(ProviderCatalog::get('llm7')['requires_placeholder_key'])->toBeTrue()
        ->and(ProviderCatalog::get('kilo')['requires_placeholder_key'])->toBeTrue();
});

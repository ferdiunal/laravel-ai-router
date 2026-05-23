<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Catalog\ProviderCatalog;

it('contains the active ai-dev-api provider platforms', function () {
    expect(array_keys(ProviderCatalog::all()))->toBe([
        'google',
        'cloudflare',
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

it('marks anonymous providers as requiring placeholder key rows', function () {
    expect(ProviderCatalog::get('pollinations')['requires_placeholder_key'])->toBeTrue()
        ->and(ProviderCatalog::get('llm7')['requires_placeholder_key'])->toBeTrue()
        ->and(ProviderCatalog::get('kilo')['requires_placeholder_key'])->toBeTrue();
});

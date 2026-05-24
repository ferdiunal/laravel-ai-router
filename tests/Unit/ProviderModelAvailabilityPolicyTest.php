<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Services\ProviderModelAvailabilityPolicy;

it('allows routable built-in live models into auto fallback regardless of free metadata', function (string $platform, array $definition, array $model): void {
    $policy = new ProviderModelAvailabilityPolicy;

    expect($policy->shouldEnableAutoFallback($platform, $definition, $model))->toBeTrue();
})->with([
    'google non-free Gemini' => [
        'google',
        ['adapter' => 'google-ai-studio', 'custom' => false],
        ['model_id' => 'gemini-2.5-flash', 'is_free' => false, 'budget_label' => 'credits-based'],
    ],
    'cloudflare non-free Workers AI' => [
        'cloudflare',
        ['adapter' => 'cloudflare-workers-ai', 'custom' => false],
        ['model_id' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'is_free' => false, 'budget_label' => 'credits-based'],
    ],
    'custom OpenAI-compatible non-free model' => [
        'custom-openai',
        ['adapter' => 'openai-compatible', 'custom' => true],
        ['model_id' => 'custom/provider-model', 'is_free' => false, 'budget_label' => 'credits-based'],
    ],
]);

it('does not auto-enable Google models that cannot use the native generateContent adapter', function (array $model): void {
    $policy = new ProviderModelAvailabilityPolicy;

    expect($policy->shouldEnableAutoFallback('google', ['adapter' => 'google-ai-studio', 'custom' => false], $model))->toBeFalse();
})->with([
    'embedding-only catalog row' => [[
        'model_id' => 'embedding-001',
        'raw_metadata' => [
            'name' => 'models/embedding-001',
            'supportedGenerationMethods' => ['embedContent'],
        ],
    ]],
    'interactions-only live model' => [[
        'model_id' => 'gemini-2.0-flash-live-001',
        'raw_metadata' => [
            'name' => 'models/gemini-2.0-flash-live-001',
            'supportedGenerationMethods' => ['generateContent', 'bidiGenerateContent'],
        ],
    ]],
    'preview model without live probe' => [[
        'model_id' => 'gemini-3-flash-preview',
        'raw_metadata' => [
            'name' => 'models/gemini-3-flash-preview',
            'supportedGenerationMethods' => ['generateContent', 'streamGenerateContent'],
        ],
    ]],
]);

it('does not auto-enable Cloudflare Workers AI rows outside the conservative chat completions safe list', function (array $model): void {
    $policy = new ProviderModelAvailabilityPolicy;

    expect($policy->shouldEnableAutoFallback('cloudflare', ['adapter' => 'cloudflare-workers-ai', 'custom' => false], $model))->toBeFalse();
})->with([
    'embedding task row' => [[
        'model_id' => '@cf/baai/bge-base-en-v1.5',
        'raw_metadata' => [
            'name' => '@cf/baai/bge-base-en-v1.5',
            'task' => ['name' => 'Text Embeddings'],
        ],
    ]],
    'broader candidate without live probe' => [[
        'model_id' => '@cf/openai/gpt-oss-120b',
        'raw_metadata' => [
            'name' => '@cf/openai/gpt-oss-120b',
            'task' => ['name' => 'Text Generation'],
        ],
    ]],
]);

it('does not enable auto fallback for provider definitions without a routable adapter', function (): void {
    $policy = new ProviderModelAvailabilityPolicy;

    expect($policy->shouldEnableAutoFallback('legacy-provider', ['custom' => true], [
        'model_id' => 'legacy/model',
        'is_free' => true,
    ]))->toBeFalse();
});

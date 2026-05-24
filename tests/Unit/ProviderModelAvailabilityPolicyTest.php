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

it('does not enable auto fallback for provider definitions without a routable adapter', function (): void {
    $policy = new ProviderModelAvailabilityPolicy;

    expect($policy->shouldEnableAutoFallback('legacy-provider', ['custom' => true], [
        'model_id' => 'legacy/model',
        'is_free' => true,
    ]))->toBeFalse();
});

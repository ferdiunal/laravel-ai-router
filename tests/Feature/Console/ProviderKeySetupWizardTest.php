<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Console\Wizards\ProviderKeySetupWizard;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\TextPrompt;

it('offers native provider adapters when choosing a provider key non-interactively', function () {
    $wizard = app(ProviderKeySetupWizard::class);
    $providerPrompt = new ReflectionMethod($wizard, 'providerPrompt');
    $providerPrompt->setAccessible(true);

    expect($providerPrompt->invoke($wizard, false))->toBe('google');
});

it('asks for cloudflare account id as separate credential metadata', function () {
    $shouldFallback = new ReflectionProperty(Prompt::class, 'shouldFallback');
    $shouldFallback->setAccessible(true);
    $fallbacks = new ReflectionProperty(Prompt::class, 'fallbacks');
    $fallbacks->setAccessible(true);

    $previousShouldFallback = $shouldFallback->getValue();
    $previousFallbacks = $fallbacks->getValue();
    $prompted = false;

    try {
        $shouldFallback->setValue(null, false);
        $fallbacks->setValue(null, []);

        TextPrompt::fallbackUsing(function (TextPrompt $prompt) use (&$prompted): string {
            $prompted = true;

            expect($prompt->label)->toBe('Cloudflare account ID')
                ->and($prompt->required)->toBeTrue();

            return 'account-123';
        });
        Prompt::fallbackWhen(true);

        $wizard = app(ProviderKeySetupWizard::class);
        $metadataPrompt = new ReflectionMethod($wizard, 'credentialMetadataPrompt');
        $metadataPrompt->setAccessible(true);

        expect($metadataPrompt->invoke($wizard, 'cloudflare', true))->toBe(['account_id' => 'account-123'])
            ->and($prompted)->toBeTrue();
    } finally {
        $shouldFallback->setValue(null, $previousShouldFallback);
        $fallbacks->setValue(null, $previousFallbacks);
    }
});

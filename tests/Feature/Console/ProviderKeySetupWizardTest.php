<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Console\Wizards\ProviderKeySetupWizard;
use Laravel\Prompts\MultiSelectPrompt;
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

it('selects all cached models for non-interactive auto routing setup without the auto pseudo model', function () {
    $wizard = app(ProviderKeySetupWizard::class);
    $modelSelectionPrompt = new ReflectionMethod($wizard, 'modelSelectionPrompt');
    $modelSelectionPrompt->setAccessible(true);

    $selected = $modelSelectionPrompt->invoke($wizard, false, [
        'auto' => 'Auto — route requests across healthy cached available models',
        'model-a' => 'Provider / Primary — Model A',
        'model-b' => 'Provider / Primary — Model B',
    ]);

    expect($selected)->toBe(['model-a', 'model-b']);
});

it('prompts for multiple auto routing models instead of one default model', function () {
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

        MultiSelectPrompt::fallbackUsing(function (MultiSelectPrompt $prompt) use (&$prompted): array {
            $prompted = true;

            expect($prompt->label)->toBe('Which models should participate in random auto routing for this provider key?')
                ->and($prompt->options)->not->toHaveKey('auto')
                ->and($prompt->default)->toBe(['model-a']);

            return ['model-b'];
        });
        Prompt::fallbackWhen(true);

        $wizard = app(ProviderKeySetupWizard::class);
        $modelSelectionPrompt = new ReflectionMethod($wizard, 'modelSelectionPrompt');
        $modelSelectionPrompt->setAccessible(true);

        expect($modelSelectionPrompt->invoke($wizard, true, [
            'auto' => 'Auto — route requests across healthy cached available models',
            'model-a' => 'Provider / Primary — Model A',
            'model-b' => 'Provider / Primary — Model B',
        ], ['model-a']))->toBe(['model-b'])
            ->and($prompted)->toBeTrue();
    } finally {
        $shouldFallback->setValue(null, $previousShouldFallback);
        $fallbacks->setValue(null, $previousFallbacks);
    }
});

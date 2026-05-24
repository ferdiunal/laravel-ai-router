<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Console\Wizards\ProviderKeySetupWizard;
use Laravel\Prompts\Prompt;

it('offers native provider adapters when choosing a provider key non-interactively', function () {
    $wizard = app(ProviderKeySetupWizard::class);
    $providerPrompt = new ReflectionMethod($wizard, 'providerPrompt');
    $providerPrompt->setAccessible(true);

    expect($providerPrompt->invoke($wizard, false))->toBe('google');
});

it('asks for cloudflare account id as separate credential metadata', function () {
    $shouldFallback = new ReflectionProperty(Prompt::class, 'shouldFallback');
    $shouldFallback->setAccessible(true);
    $shouldFallback->setValue(null, false);

    Prompt::fake(['account-123'.PHP_EOL]);

    $wizard = app(ProviderKeySetupWizard::class);
    $metadataPrompt = new ReflectionMethod($wizard, 'credentialMetadataPrompt');
    $metadataPrompt->setAccessible(true);

    expect($metadataPrompt->invoke($wizard, 'cloudflare', true))->toBe(['account_id' => 'account-123']);

    Prompt::assertStrippedOutputContains('Cloudflare account ID');
});

<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Console\Wizards\ProviderKeySetupWizard;

it('offers native provider adapters when choosing a provider key non-interactively', function () {
    $wizard = app(ProviderKeySetupWizard::class);
    $providerPrompt = new ReflectionMethod($wizard, 'providerPrompt');
    $providerPrompt->setAccessible(true);

    expect($providerPrompt->invoke($wizard, false))->toBe('google');
});

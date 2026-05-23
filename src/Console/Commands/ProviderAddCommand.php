<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Console\Wizards\ProviderKeySetupWizard;
use Illuminate\Console\Command;

/**
 * Adds an encrypted provider key through the guided provider setup workflow.
 */
final class ProviderAddCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider:add';

    protected $description = 'Add a provider API key and choose a cached free model using Laravel Prompts.';

    /**
     * Launch the provider-key setup wizard and persist the selected provider key and default model preference.
     */
    public function handle(ProviderKeySetupWizard $wizard): int
    {
        $wizard->run($this->shouldPrompt());

        return self::SUCCESS;
    }
}

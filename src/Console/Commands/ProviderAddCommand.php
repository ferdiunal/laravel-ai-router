<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Console\Wizards\ProviderKeySetupWizard;
use Illuminate\Console\Command;

final class ProviderAddCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider:add';

    protected $description = 'Add a provider API key and choose a cached free model using Laravel Prompts.';

    public function handle(ProviderKeySetupWizard $wizard): int
    {
        $wizard->run($this->shouldPrompt());

        return self::SUCCESS;
    }
}

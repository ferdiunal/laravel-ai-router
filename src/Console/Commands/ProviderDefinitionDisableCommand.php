<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Services\ProviderDefinitionManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Disables a runtime custom provider definition and deactivates its runtime routing artifacts.
 */
final class ProviderDefinitionDisableCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider-definition:disable';

    protected $description = 'Disable a custom provider definition selected by slug.';

    /**
     * Prompt for a runtime provider definition and disable it with related runtime artifacts.
     */
    public function handle(ProviderDefinitionManager $definitions): int
    {
        $definition = $this->definitionPrompt('Which custom provider definition should be disabled?');
        if ($definition === null) {
            warning('No custom provider definitions found.');

            return self::SUCCESS;
        }

        $definitions->setEnabled((int) $definition->getKey(), false);
        info("Disabled {$definition->platform} / {$definition->name}.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Services\ProviderDefinitionManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Enables an existing runtime custom provider definition for provider-key setup and routing.
 */
final class ProviderDefinitionEnableCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider-definition:enable';

    protected $description = 'Enable a custom provider definition selected by slug.';

    /**
     * Prompt for a runtime provider definition and enable it for catalog and key management workflows.
     */
    public function handle(ProviderDefinitionManager $definitions): int
    {
        $definition = $this->definitionPrompt('Which custom provider definition should be enabled?');
        if ($definition === null) {
            warning('No custom provider definitions found.');

            return self::SUCCESS;
        }

        $definitions->setEnabled((int) $definition->getKey(), true);
        info("Enabled {$definition->platform} / {$definition->name}.");

        return self::SUCCESS;
    }
}

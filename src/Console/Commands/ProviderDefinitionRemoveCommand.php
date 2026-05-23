<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Services\ProviderDefinitionManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Removes a runtime custom provider definition and disables its related routing artifacts.
 */
final class ProviderDefinitionRemoveCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider-definition:remove';

    protected $description = 'Remove a custom provider definition selected by slug.';

    /**
     * Prompt for a runtime provider definition and remove it while deactivating associated runtime artifacts.
     */
    public function handle(ProviderDefinitionManager $definitions): int
    {
        $definition = $this->definitionPrompt('Which custom provider definition should be removed?');
        if ($definition === null) {
            warning('No custom provider definitions found.');

            return self::SUCCESS;
        }

        if (! $this->confirmPrompt("Remove {$definition->platform} / {$definition->name}?", false)) {
            warning('Removal cancelled.');

            return self::SUCCESS;
        }

        $definitions->remove((int) $definition->getKey());
        info('Custom provider definition removed.');

        return self::SUCCESS;
    }
}

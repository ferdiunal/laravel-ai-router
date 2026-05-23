<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Services\ProviderKeyManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Enables an encrypted provider key so healthy cached models can participate in routing.
 */
final class ProviderEnableCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider:enable';

    protected $description = 'Enable a provider key selected by provider and label.';

    /**
     * Prompt for a provider key and enable routing through that provider-label credential.
     */
    public function handle(ProviderKeyManager $keys): int
    {
        $key = $this->keyPrompt('Which provider key should be enabled?');
        if ($key === null) {
            warning('No provider keys found.');

            return self::SUCCESS;
        }

        $keys->setEnabled((int) $key->getKey(), true);
        info("Enabled {$key->platform} / {$key->label}.");

        return self::SUCCESS;
    }
}

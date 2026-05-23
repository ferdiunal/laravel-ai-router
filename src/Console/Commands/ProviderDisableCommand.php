<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\LaravelAiRouter\Services\ProviderKeyManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Disables an encrypted provider key so it is excluded from routing and model selection.
 */
final class ProviderDisableCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'laravel-ai-router:provider:disable';

    protected $description = 'Disable a provider key selected by provider and label.';

    /**
     * Prompt for a provider key and disable routing through that provider-label credential.
     */
    public function handle(ProviderKeyManager $keys): int
    {
        $key = $this->keyPrompt('Which provider key should be disabled?');
        if ($key === null) {
            warning('No provider keys found.');

            return self::SUCCESS;
        }

        $keys->setEnabled((int) $key->getKey(), false);
        info("Disabled {$key->platform} / {$key->label}.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Services\ProviderKeyManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

final class ProviderDisableCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider:disable';

    protected $description = 'Disable a provider key selected by provider and label.';

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

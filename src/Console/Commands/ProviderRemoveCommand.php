<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Services\ProviderKeyManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

final class ProviderRemoveCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider:remove';

    protected $description = 'Remove a provider key selected by provider and label.';

    public function handle(ProviderKeyManager $keys): int
    {
        $key = $this->keyPrompt('Which provider key should be removed?');
        if ($key === null) {
            warning('No provider keys found.');

            return self::SUCCESS;
        }

        if (! $this->confirmPrompt("Remove {$key->platform} / {$key->label}?", false)) {
            warning('Removal cancelled.');

            return self::SUCCESS;
        }

        $keys->remove((int) $key->getKey());
        info('Provider key removed.');

        return self::SUCCESS;
    }
}

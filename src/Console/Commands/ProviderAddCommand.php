<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Catalog\ProviderCatalog;
use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Services\ProviderKeyManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;

final class ProviderAddCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider:add';

    protected $description = 'Add a provider API key using Laravel Prompts.';

    public function handle(ProviderKeyManager $keys): int
    {
        $platform = $this->providerPrompt('Which provider should be added?');
        $definition = ProviderCatalog::get($platform);
        $placeholder = ($definition['requires_placeholder_key'] ?? false) ? 'anonymous-placeholder' : '';
        $apiKey = $this->passwordPrompt('API key', $placeholder);
        $label = $this->textPrompt('Label', 'Primary', required: true);
        $refresh = $this->confirmPrompt('Validate/cache supported free models now?', true);

        $key = $keys->add($platform, $apiKey !== '' ? $apiKey : $placeholder, $label, $refresh);

        info("Added {$key->platform} / {$key->label} ({$key->masked_key}).");
        outro('Provider key saved.');

        return self::SUCCESS;
    }
}

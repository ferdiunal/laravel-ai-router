<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Models\AiDevApiProviderDefinition;
use Illuminate\Console\Command;

use function Laravel\Prompts\table;

final class ProviderDefinitionListCommand extends Command
{
    protected $signature = 'ai-dev-api:provider-definition:list';

    protected $description = 'List custom OpenAI-compatible provider definitions.';

    public function handle(): int
    {
        $rows = AiDevApiProviderDefinition::query()->orderBy('platform')->get()
            ->map(fn (AiDevApiProviderDefinition $definition): array => [
                (string) $definition->getKey(),
                $definition->platform,
                $definition->name,
                $definition->base_url,
                $definition->enabled ? 'yes' : 'no',
                (string) $definition->timeout_ms,
            ])
            ->all();

        table(['ID', 'Provider', 'Name', 'Base URL', 'Enabled', 'Timeout ms'], $rows);

        return self::SUCCESS;
    }
}

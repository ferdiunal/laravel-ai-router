<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderDefinition;
use Illuminate\Console\Command;

use function Laravel\Prompts\table;

/**
 * Lists runtime custom provider definitions with status and connection metadata.
 */
final class ProviderDefinitionListCommand extends Command
{
    protected $signature = 'laravel-ai-router:provider-definition:list';

    protected $description = 'List custom OpenAI-compatible provider definitions.';

    /**
     * Render runtime custom provider definitions with adapter, endpoint, status, and timeout metadata.
     */
    public function handle(): int
    {
        $rows = LaravelAiRouterProviderDefinition::query()->orderBy('platform')->get()
            ->map(fn (LaravelAiRouterProviderDefinition $definition): array => [
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

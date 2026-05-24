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
                $definition->models_endpoint_enabled ? 'enabled' : 'disabled',
                $definition->validation_method ?? 'models',
                $this->declaredModelSummary($definition),
            ])
            ->all();

        table(['ID', 'Provider', 'Name', 'Base URL', 'Enabled', 'Timeout ms', 'Models endpoint', 'Validation', 'Declared models'], $rows);

        return self::SUCCESS;
    }

    /**
     * Render a compact declared-model summary for the provider-definition table.
     */
    private function declaredModelSummary(LaravelAiRouterProviderDefinition $definition): string
    {
        $modelIds = collect($definition->declared_models ?? [])
            ->pluck('model_id')
            ->filter()
            ->values();

        if ($modelIds->isEmpty()) {
            return '-';
        }

        if ($modelIds->count() <= 3) {
            return $modelIds->implode(', ');
        }

        return $modelIds->take(3)->implode(', ').' +'.($modelIds->count() - 3).' more';
    }
}

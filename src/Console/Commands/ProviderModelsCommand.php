<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderModelCache;
use Ferdiunal\AiDevApi\Services\ModelPreferenceManager;
use Ferdiunal\AiDevApi\Services\ProviderModelCacheService;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Lists, refreshes, and selects cached free models for a specific provider key.
 */
final class ProviderModelsCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider:models';

    protected $description = 'List, refresh, search, and select cached free models for a provider key.';

    /**
     * Prompt for a provider key, optionally refresh its model cache, list cached models, and update the default model.
     */
    public function handle(ProviderModelCacheService $modelCache, ModelPreferenceManager $preferences): int
    {
        $key = $this->keyPrompt('Which provider key models should be shown?');
        if ($key === null) {
            warning('No provider keys found.');

            return self::SUCCESS;
        }

        if ($this->confirmPrompt('Refresh model cache first?', false)) {
            $modelCache->refreshForKey($key);
            $key->refresh();
            info('Model cache refreshed.');
        }

        $rows = collect($modelCache->cachedModelsForKey($key))
            ->map(fn (AiDevApiProviderModelCache $model): array => [
                $model->model_id,
                $model->display_name ?? '-',
                (string) ($model->context_window ?? '-'),
                $model->supports_tools === true ? 'yes' : 'no',
                $model->budget_label ?? '-',
                $model->source,
                optional($model->checked_at)->toDateTimeString() ?? '-',
            ])
            ->all();

        table(['Model ID', 'Label', 'Context', 'Tools', 'Budget', 'Source', 'Checked At'], $rows);

        if ($rows !== [] && $this->confirmPrompt('Select a default model from these cached models?', true)) {
            $selectedModel = $this->modelPrompt($modelCache->choicesForKey($key), default: 'auto');
            $preferences->setDefaultTextModel($selectedModel);
            info("Default text model set to {$selectedModel}.");
        }

        return self::SUCCESS;
    }
}

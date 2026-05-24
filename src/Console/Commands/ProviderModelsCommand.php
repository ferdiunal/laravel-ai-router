<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelCacheService;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelSelectionManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Lists, refreshes, and edits cached available models selected for random auto routing.
 */
final class ProviderModelsCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'laravel-ai-router:provider:models';

    protected $description = 'List, refresh, search, and select cached available models for random auto routing.';

    /**
     * Prompt for a provider key, optionally refresh its model cache, list cached models, and update auto model selection.
     */
    public function handle(ProviderModelCacheService $modelCache, ProviderModelSelectionManager $selection): int
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

        $selectedIds = $selection->selectedModelIdsForKey($key);

        $rows = collect($modelCache->cachedModelsForKey($key))
            ->map(fn (LaravelAiRouterProviderModelCache $model): array => [
                $model->model_id,
                in_array($model->model_id, $selectedIds, true) ? 'yes' : 'no',
                $model->display_name ?? '-',
                (string) ($model->context_window ?? '-'),
                $model->supports_tools === true ? 'yes' : ($model->supports_tools === false ? 'no' : 'unknown'),
                $model->budget_label ?? '-',
                $model->source,
                optional($model->checked_at)->toDateTimeString() ?? '-',
            ])
            ->all();

        table(['Model ID', 'Auto', 'Label', 'Context', 'Tools', 'Budget', 'Source', 'Checked At'], $rows);

        if ($rows !== [] && $this->confirmPrompt('Select models for random auto routing?', true)) {
            $selectedModelIds = $this->multiModelPrompt(
                $modelCache->choicesForKey($key, includeAuto: false),
                $selectedIds,
                'Which models should participate in random auto routing?',
            );
            $selection->setSelectedModelIdsForKey($key, $selectedModelIds);
            info('Selected '.count($selectedModelIds).' model(s) for random auto routing.');
        }

        return self::SUCCESS;
    }
}

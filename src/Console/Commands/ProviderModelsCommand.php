<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Console\Commands;

use Ferdiunal\AiDevApi\Console\Concerns\InteractsWithProviderPrompts;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderModelCache;
use Ferdiunal\AiDevApi\Services\ProviderModelCacheService;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class ProviderModelsCommand extends Command
{
    use InteractsWithProviderPrompts;

    protected $signature = 'ai-dev-api:provider:models';

    protected $description = 'List or refresh cached free models for a provider key.';

    public function handle(ProviderModelCacheService $modelCache): int
    {
        $key = $this->keyPrompt('Which provider key models should be shown?');
        if ($key === null) {
            warning('No provider keys found.');

            return self::SUCCESS;
        }

        if ($this->confirmPrompt('Refresh model cache first?', false)) {
            $modelCache->refreshForKey($key);
            info('Model cache refreshed.');
        }

        $rows = AiDevApiProviderModelCache::query()
            ->where('provider_key_id', $key->getKey())
            ->where('enabled', true)
            ->orderBy('model_id')
            ->get()
            ->map(fn (AiDevApiProviderModelCache $model): array => [
                $model->model_id,
                $model->display_name ?? '-',
                (string) ($model->context_window ?? '-'),
                $model->budget_label ?? '-',
                $model->source,
                optional($model->checked_at)->toDateTimeString() ?? '-',
            ])
            ->all();

        table(['Model ID', 'Label', 'Context', 'Budget', 'Source', 'Checked At'], $rows);

        return self::SUCCESS;
    }
}

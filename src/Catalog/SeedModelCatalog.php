<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Catalog;

use Ferdiunal\AiDevApi\Models\AiDevApiFallback;
use Ferdiunal\AiDevApi\Models\AiDevApiModel;
use Illuminate\Support\Facades\DB;

final class SeedModelCatalog
{
    public function seed(): void
    {
        DB::transaction(function (): void {
            foreach (ModelCatalog::all() as $model) {
                AiDevApiModel::query()->updateOrCreate(
                    [
                        'platform' => $model['platform'],
                        'model_id' => $model['model_id'],
                    ],
                    [
                        'display_name' => $model['display_name'],
                        'intelligence_rank' => $model['intelligence_rank'],
                        'speed_rank' => $model['speed_rank'],
                        'rpm_limit' => $model['rpm_limit'],
                        'rpd_limit' => $model['rpd_limit'],
                        'tpm_limit' => $model['tpm_limit'],
                        'tpd_limit' => $model['tpd_limit'],
                        'budget_label' => $model['budget_label'],
                        'context_window' => $model['context_window'],
                        'enabled' => $model['enabled'],
                    ],
                );
            }

            $nextPriority = ((int) AiDevApiFallback::query()->max('priority')) + 1;

            AiDevApiModel::query()
                ->orderBy('intelligence_rank')
                ->orderBy('id')
                ->each(function (AiDevApiModel $model) use (&$nextPriority): void {
                    $exists = AiDevApiFallback::query()
                        ->where('ai_dev_api_model_id', $model->getKey())
                        ->exists();

                    if ($exists) {
                        return;
                    }

                    AiDevApiFallback::query()->create([
                        'ai_dev_api_model_id' => $model->getKey(),
                        'priority' => $nextPriority++,
                        'enabled' => true,
                        'penalty' => 0,
                    ]);
                });
        });
    }
}

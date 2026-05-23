<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Catalog;

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Illuminate\Support\Facades\DB;

/**
 * Seeds package-owned model and fallback rows from the curated model catalog without overwriting existing routing priorities.
 */
final class SeedModelCatalog
{
    /**
     * Seed package model and fallback rows transactionally on the package database connection.
     */
    public function seed(): void
    {
        DB::connection(config('laravel-ai-router.database.connection') ?: 'laravel-ai-router')->transaction(function (): void {
            foreach (ModelCatalog::all() as $model) {
                LaravelAiRouterModel::query()->updateOrCreate(
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

            $nextPriority = ((int) LaravelAiRouterFallback::query()->max('priority')) + 1;

            LaravelAiRouterModel::query()
                ->orderBy('intelligence_rank')
                ->orderBy('id')
                ->each(function (LaravelAiRouterModel $model) use (&$nextPriority): void {
                    $exists = LaravelAiRouterFallback::query()
                        ->where('laravel_ai_router_model_id', $model->getKey())
                        ->exists();

                    if ($exists) {
                        return;
                    }

                    LaravelAiRouterFallback::query()->create([
                        'laravel_ai_router_model_id' => $model->getKey(),
                        'priority' => $nextPriority++,
                        'enabled' => true,
                        'penalty' => 0,
                    ]);
                });
        });
    }
}

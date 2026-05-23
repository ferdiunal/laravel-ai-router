<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Routing\ModelRouter;
use Ferdiunal\LaravelAiRouter\Services\ProviderKeyManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelCacheService;

function migrateLaravelAiRouterForNvidiaLiveSmokeTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('discovers nvidia models from the live models endpoint when explicitly enabled', function (): void {
    $apiKey = (string) (getenv('NVIDIA_API_KEY') ?: getenv('NVAPI_API_KEY'));

    migrateLaravelAiRouterForNvidiaLiveSmokeTests();

    $key = app(ProviderKeyManager::class)->add('nvidia', trim($apiKey), 'Live NVIDIA', refreshModels: true);
    $models = app(ProviderModelCacheService::class)->cachedModelsForKey($key);

    expect($models)->not->toBeEmpty();

    $firstModelId = $models[0]->model_id;

    expect(collect($models)->pluck('platform')->unique()->values()->all())->toBe(['nvidia']);
    expect(collect($models)->every(fn ($model): bool => $model->is_free === false))->toBeTrue();
    expect(collect($models)->pluck('budget_label')->unique()->values()->all())->toBe(['credits-based']);

    $route = app(ModelRouter::class)->route($firstModelId);

    expect($route->platform)->toBe('nvidia')
        ->and($route->modelId)->toBe($firstModelId)
        ->and($route->keyId)->toBe($key->getKey());

    $nvidiaModelIds = LaravelAiRouterModel::query()
        ->where('platform', 'nvidia')
        ->pluck('id');

    expect(LaravelAiRouterFallback::query()
        ->whereIn('laravel_ai_router_model_id', $nvidiaModelIds)
        ->where('enabled', true)
        ->exists())->toBeFalse();
})->skip(
    getenv('LARAVEL_AI_ROUTER_LIVE_NVIDIA_MODELS') !== '1'
    || trim((string) (getenv('NVIDIA_API_KEY') ?: getenv('NVAPI_API_KEY'))) === '',
    'Set LARAVEL_AI_ROUTER_LIVE_NVIDIA_MODELS=1 and NVIDIA_API_KEY or NVAPI_API_KEY to run this opt-in live smoke test.',
)->group('live', 'nvidia');

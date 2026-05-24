<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Exceptions\NoAvailableModelException;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Routing\ModelRouter;

function migrateLaravelAiRouterForSelectedProviderRoutingTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

function createSelectedProviderRoutingKey(string $label, string $platform = 'openrouter'): LaravelAiRouterProviderKey
{
    return LaravelAiRouterProviderKey::query()->create([
        'platform' => $platform,
        'label' => $label,
        'key' => 'key-'.$platform.'-'.strtolower($label).'-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);
}

function createSelectedProviderRoutingModel(string $modelId, int $priority, bool $fallbackEnabled = true, string $platform = 'openrouter'): LaravelAiRouterModel
{
    $model = LaravelAiRouterModel::query()->create([
        'platform' => $platform,
        'model_id' => $modelId,
        'display_name' => $modelId,
        'intelligence_rank' => $priority,
        'speed_rank' => $priority,
        'enabled' => true,
    ]);

    LaravelAiRouterFallback::query()->create([
        'laravel_ai_router_model_id' => $model->getKey(),
        'priority' => $priority,
        'enabled' => $fallbackEnabled,
        'penalty' => 0,
    ]);

    return $model;
}

/**
 * @param  array<string, mixed>|null  $rawMetadata
 */
function createSelectedProviderRoutingCache(LaravelAiRouterProviderKey $key, LaravelAiRouterModel $model, bool $autoEnabled, ?bool $supportsTools = null, ?array $rawMetadata = null): LaravelAiRouterProviderModelCache
{
    return LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => $model->platform,
        'provider_label' => $key->label,
        'model_id' => $model->model_id,
        'display_name' => $model->display_name,
        'is_free' => true,
        'supports_tools' => $supportsTools,
        'enabled' => true,
        'auto_enabled' => $autoEnabled,
        'source' => 'live',
        'raw_metadata' => $rawMetadata,
        'checked_at' => now(),
    ]);
}

it('falls back to legacy fallback routing while no provider model cache has been initialized', function (): void {
    migrateLaravelAiRouterForSelectedProviderRoutingTests();

    $key = createSelectedProviderRoutingKey('Primary');
    createSelectedProviderRoutingModel('legacy-auto-model', priority: 1);

    config()->set('laravel-ai-router.routing.auto_strategy', 'random_provider');

    $route = app(ModelRouter::class)->route('auto');

    expect($route->modelId)->toBe('legacy-auto-model')
        ->and($route->providerLabel)->toBe($key->label);
});

it('does not fall back to unselected cached models once provider model cache exists', function (): void {
    migrateLaravelAiRouterForSelectedProviderRoutingTests();

    $key = createSelectedProviderRoutingKey('Primary');
    $unselected = createSelectedProviderRoutingModel('unselected-auto-model', priority: 1);
    createSelectedProviderRoutingCache($key, $unselected, autoEnabled: false);

    config()->set('laravel-ai-router.routing.auto_strategy', 'random_provider');

    app(ModelRouter::class)->route('auto');
})->throws(NoAvailableModelException::class, 'All selected Laravel AI Router provider models are exhausted');

it('auto routes only through selected cached provider models while exact routing can use unselected cache rows', function (): void {
    migrateLaravelAiRouterForSelectedProviderRoutingTests();

    $key = createSelectedProviderRoutingKey('Primary');
    $unselected = createSelectedProviderRoutingModel('unselected-model', priority: 1);
    $selected = createSelectedProviderRoutingModel('selected-model', priority: 2);
    createSelectedProviderRoutingCache($key, $unselected, autoEnabled: false);
    createSelectedProviderRoutingCache($key, $selected, autoEnabled: true);

    config()->set('laravel-ai-router.routing.auto_strategy', 'random_provider');
    config()->set('laravel-ai-router.routing.random_seed', 1);

    $autoRoute = app(ModelRouter::class)->route('auto');
    $exactRoute = app(ModelRouter::class)->route('unselected-model');

    expect($autoRoute->modelId)->toBe('selected-model')
        ->and($exactRoute->modelId)->toBe('unselected-model');
});

it('random provider strategy weights provider keys before shuffling models inside the selected provider', function (): void {
    migrateLaravelAiRouterForSelectedProviderRoutingTests();

    $crowded = createSelectedProviderRoutingKey('Crowded');
    $tiny = createSelectedProviderRoutingKey('Tiny');

    for ($i = 1; $i <= 10; $i++) {
        $model = createSelectedProviderRoutingModel('crowded-model-'.$i, priority: $i);
        createSelectedProviderRoutingCache($crowded, $model, autoEnabled: true);
    }

    $tinyModel = createSelectedProviderRoutingModel('tiny-model', priority: 11);
    createSelectedProviderRoutingCache($tiny, $tinyModel, autoEnabled: true);

    config()->set('laravel-ai-router.routing.auto_strategy', 'random_provider');
    config()->set('laravel-ai-router.routing.random_seed', 2);

    $route = app(ModelRouter::class)->route('auto');

    expect($route->providerLabel)->toBe('Tiny')
        ->and($route->modelId)->toBe('tiny-model');
});

it('selected auto routing still skips cache rows without required tool support', function (): void {
    migrateLaravelAiRouterForSelectedProviderRoutingTests();

    $key = createSelectedProviderRoutingKey('Primary');
    $withoutTools = createSelectedProviderRoutingModel('selected-no-tools', priority: 1);
    $withTools = createSelectedProviderRoutingModel('selected-with-tools', priority: 2);
    createSelectedProviderRoutingCache($key, $withoutTools, autoEnabled: true, supportsTools: false);
    createSelectedProviderRoutingCache($key, $withTools, autoEnabled: true, supportsTools: true);

    config()->set('laravel-ai-router.routing.auto_strategy', 'random_provider');
    config()->set('laravel-ai-router.routing.random_seed', 1);

    $route = app(ModelRouter::class)->route('auto', requiresTools: true);

    expect($route->modelId)->toBe('selected-with-tools');
});

it('selected auto routing ignores provider-specific non-chat model rows even if they were previously selected', function (): void {
    migrateLaravelAiRouterForSelectedProviderRoutingTests();

    $key = createSelectedProviderRoutingKey('Google Live', platform: 'google');
    $liveOnly = createSelectedProviderRoutingModel('gemini-2.0-flash-live-001', priority: 1, platform: 'google');
    createSelectedProviderRoutingCache(
        $key,
        $liveOnly,
        autoEnabled: true,
        rawMetadata: [
            'name' => 'models/gemini-2.0-flash-live-001',
            'supportedGenerationMethods' => ['generateContent', 'bidiGenerateContent'],
        ],
    );

    config()->set('laravel-ai-router.routing.auto_strategy', 'random_provider');

    app(ModelRouter::class)->route('auto');
})->throws(NoAvailableModelException::class, 'All selected Laravel AI Router provider models are exhausted');

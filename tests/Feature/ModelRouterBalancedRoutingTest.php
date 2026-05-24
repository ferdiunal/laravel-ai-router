<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Catalog\SeedModelCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Routing\ModelRouter;

function migrateLaravelAiRouterForBalancedRoutingTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

/**
 * @param  list<string>  $platforms
 * @return array<string, LaravelAiRouterModel>
 */
function seedBalancedRoutingFallbackOrder(array $platforms): array
{
    app(SeedModelCatalog::class)->seed();

    LaravelAiRouterFallback::query()->update(['enabled' => false]);

    $models = [];

    foreach (array_values($platforms) as $index => $platform) {
        $model = LaravelAiRouterModel::query()
            ->where('platform', $platform)
            ->orderBy('id')
            ->firstOrFail();

        LaravelAiRouterFallback::query()
            ->where('laravel_ai_router_model_id', $model->getKey())
            ->update([
                'enabled' => true,
                'priority' => $index + 1,
                'penalty' => 0,
                'penalty_updated_at' => null,
            ]);

        $models[$platform] = $model;
    }

    return $models;
}

function createBalancedRoutingProviderKey(string $platform, string $label): LaravelAiRouterProviderKey
{
    return LaravelAiRouterProviderKey::query()->create([
        'platform' => $platform,
        'label' => $label,
        'key' => 'key-'.$platform.'-'.strtolower($label).'-value-123456',
        'status' => 'healthy',
        'enabled' => true,
    ]);
}

it('keeps priority auto strategy ordered by effective priority', function () {
    migrateLaravelAiRouterForBalancedRoutingTests();

    seedBalancedRoutingFallbackOrder(['cerebras', 'openrouter', 'kilo', 'github']);

    createBalancedRoutingProviderKey('cerebras', 'Primary');
    createBalancedRoutingProviderKey('openrouter', 'Secondary');
    createBalancedRoutingProviderKey('kilo', 'Tertiary');
    createBalancedRoutingProviderKey('github', 'Outside Pool');

    config()->set('laravel-ai-router.routing.auto_strategy', 'priority');
    config()->set('laravel-ai-router.routing.random_pool_size', 3);
    config()->set('laravel-ai-router.routing.random_priority_window', 100);
    config()->set('laravel-ai-router.routing.random_seed', 1234);

    $route = app(ModelRouter::class)->route('auto');

    expect($route->platform)->toBe('cerebras')
        ->and($route->modelId)->toBe('qwen3-235b');
});

it('defaults auto routing to full random provider and model rotation', function () {
    expect(config('laravel-ai-router.routing.auto_strategy'))->toBe('random');
});

it('random auto strategy shuffles the entire enabled fallback list', function () {
    migrateLaravelAiRouterForBalancedRoutingTests();

    seedBalancedRoutingFallbackOrder(['cerebras', 'openrouter', 'kilo', 'github']);

    createBalancedRoutingProviderKey('cerebras', 'Primary');
    createBalancedRoutingProviderKey('openrouter', 'Secondary');
    createBalancedRoutingProviderKey('kilo', 'Tertiary');
    createBalancedRoutingProviderKey('github', 'Outside Pool');

    config()->set('laravel-ai-router.routing.auto_strategy', 'random');
    config()->set('laravel-ai-router.routing.random_pool_size', 1);
    config()->set('laravel-ai-router.routing.random_priority_window', 0);
    config()->set('laravel-ai-router.routing.random_seed', 1234);

    $route = app(ModelRouter::class)->route('auto');

    expect($route->platform)->toBe('openrouter')
        ->and($route->platform)->not->toBe('cerebras');
});

it('balanced random auto strategy shuffles only the top safe fallback pool', function () {
    migrateLaravelAiRouterForBalancedRoutingTests();

    seedBalancedRoutingFallbackOrder(['cerebras', 'openrouter', 'kilo', 'github']);

    createBalancedRoutingProviderKey('cerebras', 'Primary');
    createBalancedRoutingProviderKey('openrouter', 'Secondary');
    createBalancedRoutingProviderKey('kilo', 'Tertiary');
    createBalancedRoutingProviderKey('github', 'Outside Pool');

    config()->set('laravel-ai-router.routing.auto_strategy', 'balanced_random');
    config()->set('laravel-ai-router.routing.random_pool_size', 3);
    config()->set('laravel-ai-router.routing.random_priority_window', 100);
    config()->set('laravel-ai-router.routing.random_seed', 1234);

    $route = app(ModelRouter::class)->route('auto');

    expect($route->platform)->toBe('kilo')
        ->and($route->platform)->not->toBe('github')
        ->and($route->platform)->not->toBe('cerebras');
});

it('balanced random auto strategy keeps existing eligibility filters', function () {
    migrateLaravelAiRouterForBalancedRoutingTests();

    seedBalancedRoutingFallbackOrder(['cerebras', 'openrouter', 'kilo', 'github']);

    createBalancedRoutingProviderKey('openrouter', 'Only Eligible In Pool');
    createBalancedRoutingProviderKey('github', 'Outside Pool');

    config()->set('laravel-ai-router.routing.auto_strategy', 'balanced_random');
    config()->set('laravel-ai-router.routing.random_pool_size', 3);
    config()->set('laravel-ai-router.routing.random_priority_window', 100);
    config()->set('laravel-ai-router.routing.random_seed', 1234);

    $route = app(ModelRouter::class)->route('auto');

    expect($route->platform)->toBe('openrouter')
        ->and($route->modelId)->toBe('qwen/qwen3-coder:free');
});

it('balanced random auto strategy does not change exact model routing by default', function () {
    migrateLaravelAiRouterForBalancedRoutingTests();

    seedBalancedRoutingFallbackOrder(['cerebras', 'openrouter', 'kilo', 'github']);

    createBalancedRoutingProviderKey('cerebras', 'Primary');
    createBalancedRoutingProviderKey('openrouter', 'Secondary');
    createBalancedRoutingProviderKey('kilo', 'Tertiary');
    createBalancedRoutingProviderKey('github', 'Exact');

    config()->set('laravel-ai-router.routing.auto_strategy', 'balanced_random');
    config()->set('laravel-ai-router.routing.random_pool_size', 3);
    config()->set('laravel-ai-router.routing.random_priority_window', 100);
    config()->set('laravel-ai-router.routing.random_seed', 1234);

    $route = app(ModelRouter::class)->route('gpt-4o');

    expect($route->platform)->toBe('github')
        ->and($route->modelId)->toBe('gpt-4o');
});

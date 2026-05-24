<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Services\ModelPreferenceManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelSelectionManager;
use Ferdiunal\LaravelAiRouter\Tests\TestCase;

function migrateLaravelAiRouterForProviderModelsCommandTests(): void
{
    foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('edits auto routing model selection without changing the global default text model preference', function (): void {
    /** @var TestCase $this */
    migrateLaravelAiRouterForProviderModelsCommandTests();

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => 'key-openrouter-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => 'openrouter',
        'provider_label' => 'Primary',
        'model_id' => 'model-a',
        'display_name' => 'Model A',
        'is_free' => true,
        'enabled' => true,
        'auto_enabled' => true,
        'source' => 'live',
        'checked_at' => now(),
    ]);
    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => 'openrouter',
        'provider_label' => 'Primary',
        'model_id' => 'model-b',
        'display_name' => 'Model B',
        'is_free' => true,
        'enabled' => true,
        'auto_enabled' => false,
        'source' => 'live',
        'checked_at' => now(),
    ]);

    app(ModelPreferenceManager::class)->setDefaultTextModel('explicit-model');

    $this->artisan('laravel-ai-router:provider:models --no-interaction')
        ->assertSuccessful();

    expect(app(ModelPreferenceManager::class)->defaultTextModel())->toBe('explicit-model')
        ->and(app(ProviderModelSelectionManager::class)->selectedModelIdsForKey($key))->toBe(['model-a']);
});

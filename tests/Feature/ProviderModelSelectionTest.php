<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelSelectionManager;
use Illuminate\Validation\ValidationException;

function migrateLaravelAiRouterForProviderModelSelectionTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

function createProviderModelSelectionKey(string $label): LaravelAiRouterProviderKey
{
    return LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => $label,
        'key' => 'key-openrouter-'.strtolower($label).'-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);
}

function createProviderModelSelectionCache(LaravelAiRouterProviderKey $key, string $modelId, bool $autoEnabled = false): LaravelAiRouterProviderModelCache
{
    return LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => $key->platform,
        'provider_label' => $key->label,
        'model_id' => $modelId,
        'display_name' => $modelId,
        'is_free' => true,
        'enabled' => true,
        'auto_enabled' => $autoEnabled,
        'source' => 'live',
        'checked_at' => now(),
    ]);
}

it('casts cached model auto selection as a boolean flag', function (): void {
    migrateLaravelAiRouterForProviderModelSelectionTests();

    $key = createProviderModelSelectionKey('Primary');
    $cache = createProviderModelSelectionCache($key, 'model-a', autoEnabled: true);

    expect($cache->refresh()->auto_enabled)->toBeTrue();
});

it('stores selected auto models per provider key without leaking between keys', function (): void {
    migrateLaravelAiRouterForProviderModelSelectionTests();

    $primary = createProviderModelSelectionKey('Primary');
    $secondary = createProviderModelSelectionKey('Secondary');

    createProviderModelSelectionCache($primary, 'shared-model');
    createProviderModelSelectionCache($primary, 'primary-only');
    createProviderModelSelectionCache($primary, 'not-selected');
    createProviderModelSelectionCache($secondary, 'shared-model');

    app(ProviderModelSelectionManager::class)->setSelectedModelIdsForKey($primary, ['shared-model', 'primary-only']);

    expect(app(ProviderModelSelectionManager::class)->selectedModelIdsForKey($primary))->toBe(['primary-only', 'shared-model'])
        ->and(app(ProviderModelSelectionManager::class)->selectedModelIdsForKey($secondary))->toBe([])
        ->and(LaravelAiRouterProviderModelCache::query()
            ->where('provider_key_id', $secondary->getKey())
            ->where('model_id', 'shared-model')
            ->value('auto_enabled'))->toBeFalse();
});

it('rejects unknown model ids when selecting auto models for a provider key', function (): void {
    migrateLaravelAiRouterForProviderModelSelectionTests();

    $key = createProviderModelSelectionKey('Primary');
    createProviderModelSelectionCache($key, 'known-model');

    app(ProviderModelSelectionManager::class)->setSelectedModelIdsForKey($key, ['known-model', 'missing-model']);
})->throws(ValidationException::class);

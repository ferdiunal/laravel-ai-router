<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;

function migrateLaravelAiRouterBeforeAutoRotationBackfill(): void
{
    foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $migrationFile) {
        if (str_contains($migrationFile, '2026_01_01_000010_enable_cached_provider_models_for_auto_rotation')) {
            continue;
        }

        $migration = include $migrationFile;
        $migration->up();
    }
}

function runAutoRotationBackfillMigration(): void
{
    $migrationFile = __DIR__.'/../../../database/migrations/2026_01_01_000010_enable_cached_provider_models_for_auto_rotation.php';

    expect(file_exists($migrationFile))->toBeTrue();

    $migration = include $migrationFile;
    $migration->up();
}

it('enables existing disabled fallbacks for routable cached models with enabled provider keys', function (): void {
    migrateLaravelAiRouterBeforeAutoRotationBackfill();

    $model = LaravelAiRouterModel::query()->create([
        'platform' => 'google',
        'model_id' => 'gemini-2.5-flash',
        'display_name' => 'Gemini 2.5 Flash',
        'intelligence_rank' => 10,
        'speed_rank' => 10,
        'enabled' => true,
    ]);

    LaravelAiRouterFallback::query()->create([
        'laravel_ai_router_model_id' => $model->getKey(),
        'priority' => 7,
        'enabled' => false,
        'penalty' => 3,
    ]);

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'google',
        'label' => 'Gemini',
        'key' => 'key-google-value-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => 'google',
        'provider_label' => 'Gemini',
        'model_id' => 'gemini-2.5-flash',
        'display_name' => 'Gemini 2.5 Flash',
        'is_free' => false,
        'enabled' => true,
        'source' => 'live',
        'checked_at' => now(),
    ]);

    runAutoRotationBackfillMigration();

    $fallback = LaravelAiRouterFallback::query()
        ->where('laravel_ai_router_model_id', $model->getKey())
        ->firstOrFail();

    expect($fallback->enabled)->toBeTrue()
        ->and($fallback->priority)->toBe(7)
        ->and($fallback->penalty)->toBe(3);
});

it('creates missing fallback rows only for enabled non-invalid provider key caches', function (): void {
    migrateLaravelAiRouterBeforeAutoRotationBackfill();

    $cloudflareModel = LaravelAiRouterModel::query()->create([
        'platform' => 'cloudflare',
        'model_id' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'display_name' => 'Llama 3.3 70B fp8-fast',
        'intelligence_rank' => 20,
        'speed_rank' => 20,
        'enabled' => true,
    ]);
    $invalidModel = LaravelAiRouterModel::query()->create([
        'platform' => 'ollama',
        'model_id' => 'gpt-oss:20b',
        'display_name' => 'GPT OSS 20B',
        'intelligence_rank' => 30,
        'speed_rank' => 30,
        'enabled' => true,
    ]);

    $validKey = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'cloudflare',
        'label' => 'Workers',
        'key' => 'cf-token-secret-123456',
        'credential_metadata' => ['account_id' => 'account-123'],
        'status' => 'unknown',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);
    $invalidKey = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'ollama',
        'label' => 'Ollama',
        'key' => 'key-ollama-value-123456',
        'status' => 'invalid',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $validKey->getKey(),
        'platform' => 'cloudflare',
        'provider_label' => 'Workers',
        'model_id' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'display_name' => 'Llama 3.3 70B fp8-fast',
        'is_free' => false,
        'enabled' => true,
        'source' => 'live',
        'checked_at' => now(),
    ]);
    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $invalidKey->getKey(),
        'platform' => 'ollama',
        'provider_label' => 'Ollama',
        'model_id' => 'gpt-oss:20b',
        'display_name' => 'GPT OSS 20B',
        'is_free' => false,
        'enabled' => true,
        'source' => 'live',
        'checked_at' => now(),
    ]);

    runAutoRotationBackfillMigration();

    expect(LaravelAiRouterFallback::query()->where('laravel_ai_router_model_id', $cloudflareModel->getKey())->where('enabled', true)->exists())->toBeTrue()
        ->and(LaravelAiRouterFallback::query()->where('laravel_ai_router_model_id', $invalidModel->getKey())->exists())->toBeFalse();
});

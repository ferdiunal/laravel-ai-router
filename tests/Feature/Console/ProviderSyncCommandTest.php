<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterRateWindow;
use Ferdiunal\LaravelAiRouter\Services\ProviderDefinitionManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderKeyManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelSelectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function migrateLaravelAiRouterForProviderSyncCommandTests(): void
{
    foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('fails non-interactively without an explicit sync target', function (): void {
    migrateLaravelAiRouterForProviderSyncCommandTests();

    $exitCode = Artisan::call('laravel-ai-router:provider:sync', [
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Use --all, --provider=, or --key-id=');
});

it('validates a provider key, refreshes models, preserves selected auto rows, and emits secret-free json', function (): void {
    migrateLaravelAiRouterForProviderSyncCommandTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                ['id' => 'qwen/qwen3-coder:free', 'name' => 'Qwen3 Coder', 'context_length' => 262144, 'supported_parameters' => ['tools']],
                ['id' => 'paid/model', 'name' => 'Paid Model'],
            ],
        ]),
    ]);

    $secret = 'sync-secret-openrouter-key-123456';
    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => $secret,
        'status' => 'unknown',
        'enabled' => true,
        'models_cached_at' => now()->subDay(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => 'openrouter',
        'provider_label' => 'Primary',
        'model_id' => 'qwen/qwen3-coder:free',
        'display_name' => 'Old Qwen Label',
        'is_free' => true,
        'enabled' => true,
        'auto_enabled' => true,
        'source' => 'live',
        'checked_at' => now()->subDay(),
    ]);

    $exitCode = Artisan::call('laravel-ai-router:provider:sync', [
        '--key-id' => $key->getKey(),
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain($secret)
        ->and($payload['results'][0]['api_status'])->toBe('healthy')
        ->and($payload['results'][0]['models_refreshed'])->toBeTrue()
        ->and($payload['results'][0]['cached_model_count'])->toBe(2)
        ->and($payload['results'][0]['selected_auto_model_count'])->toBe(1)
        ->and($payload['results'][0]['quota']['source'])->toBe('local_estimate')
        ->and($key->refresh()->status)->toBe('healthy')
        ->and($key->last_checked_at)->not->toBeNull()
        ->and(LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->count())->toBe(2)
        ->and(app(ProviderModelSelectionManager::class)->selectedModelIdsForKey($key))->toBe(['qwen/qwen3-coder:free']);
});

it('validates models-less custom providers through chat without leaking secrets', function (): void {
    migrateLaravelAiRouterForProviderSyncCommandTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'sync-opengateway',
        name: 'Sync OpenGateway',
        baseUrl: 'https://example.com/gateway/v1',
        modelsEndpointEnabled: false,
        validationMethod: 'chat',
        validationModel: 'mimo-v2.5-pro',
        declaredModels: [[
            'model_id' => 'mimo-v2.5-pro',
            'display_name' => 'MIMO v2.5 Pro',
            'budget_label' => 'credits-based',
            'auto_enabled' => true,
        ]],
    );

    Http::fake([
        'https://example.com/gateway/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl_validation_1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'mimo-v2.5-pro',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ]),
        'https://example.com/gateway/v1/models' => Http::response(['error' => ['message' => 'models unavailable']], 404),
    ]);

    $credential = 'sync-opengateway-test-credential-123456';
    $key = app(ProviderKeyManager::class)->add('sync-opengateway', $credential, 'Gateway', refreshModels: true);

    $exitCode = Artisan::call('laravel-ai-router:provider:sync', [
        '--key-id' => $key->getKey(),
        '--no-refresh-models' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain($credential)
        ->and($payload['results'][0]['api_status'])->toBe('healthy')
        ->and($payload['results'][0]['cached_model_count'])->toBe(1)
        ->and($payload['results'][0]['selected_auto_model_count'])->toBe(1)
        ->and(app(ProviderModelSelectionManager::class)->selectedModelIdsForKey($key))->toBe(['mimo-v2.5-pro']);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://example.com/gateway/v1/chat/completions'
            && $request['model'] === 'mimo-v2.5-pro'
            && $request['max_tokens'] === 1;
    });
    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://example.com/gateway/v1/models');
});

it('marks invalid credentials invalid, disables stale cache rows, and fails when requested', function (): void {
    migrateLaravelAiRouterForProviderSyncCommandTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(['error' => ['message' => 'invalid api key']], 401),
    ]);

    $secret = 'sync-invalid-openrouter-key-123456';
    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Invalid',
        'key' => $secret,
        'status' => 'healthy',
        'enabled' => true,
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => 'openrouter',
        'provider_label' => 'Invalid',
        'model_id' => 'stale/model',
        'display_name' => 'Stale Model',
        'is_free' => true,
        'enabled' => true,
        'auto_enabled' => true,
        'source' => 'live',
        'checked_at' => now(),
    ]);

    $exitCode = Artisan::call('laravel-ai-router:provider:sync', [
        '--key-id' => $key->getKey(),
        '--no-refresh-models' => true,
        '--fail-on-invalid' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($output)->not->toContain($secret)
        ->and($payload['results'][0]['api_status'])->toBe('invalid')
        ->and($payload['results'][0]['models_refreshed'])->toBeFalse()
        ->and($key->refresh()->status)->toBe('invalid')
        ->and(LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->where('enabled', true)->count())->toBe(0);
});

it('renders default sync output as model-level quota table rows', function (): void {
    migrateLaravelAiRouterForProviderSyncCommandTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(['data' => []]),
    ]);

    $secret = 'sync-table-openrouter-key-123456';
    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => $secret,
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => 'openrouter',
        'provider_label' => 'Primary',
        'model_id' => 'limited/model',
        'display_name' => 'Limited Model',
        'rpm_limit' => 1,
        'rpd_limit' => 10,
        'tpm_limit' => 100,
        'tpd_limit' => 1000,
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
        'model_id' => 'ready/model',
        'display_name' => 'Ready Model',
        'rpm_limit' => 5,
        'rpd_limit' => 50,
        'tpm_limit' => 500,
        'tpd_limit' => 5000,
        'is_free' => true,
        'enabled' => true,
        'auto_enabled' => true,
        'source' => 'live',
        'checked_at' => now(),
    ]);

    LaravelAiRouterRateWindow::query()->create([
        'platform' => 'openrouter',
        'model_id' => 'limited/model',
        'provider_key_id' => $key->getKey(),
        'window_type' => 'rpm',
        'window_starts_at' => now()->startOfMinute(),
        'window_ends_at' => now()->startOfMinute()->addMinute(),
        'request_count' => 1,
        'token_count' => 0,
    ]);

    $exitCode = Artisan::call('laravel-ai-router:provider:sync', [
        '--key-id' => $key->getKey(),
        '--no-refresh-models' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain($secret)
        ->and($output)->toContain('Model')
        ->and($output)->toContain('RPM')
        ->and($output)->toContain('limited/model')
        ->and($output)->toContain('ready/model')
        ->and($output)->toContain('0/1')
        ->and($output)->toContain('5/5')
        ->and($output)->not->toContain('local_estimate');
});

it('reports a local rate limited key when all selected auto models are blocked by active windows', function (): void {
    migrateLaravelAiRouterForProviderSyncCommandTests();

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(['data' => []]),
    ]);

    $key = LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Limited',
        'key' => 'sync-limited-openrouter-key-123456',
        'status' => 'healthy',
        'enabled' => true,
        'models_cached_at' => now(),
        'models_cache_expires_at' => now()->addHour(),
    ]);

    LaravelAiRouterProviderModelCache::query()->create([
        'provider_key_id' => $key->getKey(),
        'platform' => 'openrouter',
        'provider_label' => 'Limited',
        'model_id' => 'limited/model',
        'display_name' => 'Limited Model',
        'rpm_limit' => 1,
        'rpd_limit' => 10,
        'tpm_limit' => 100,
        'tpd_limit' => 1000,
        'is_free' => true,
        'enabled' => true,
        'auto_enabled' => true,
        'source' => 'live',
        'checked_at' => now(),
    ]);

    LaravelAiRouterRateWindow::query()->create([
        'platform' => 'openrouter',
        'model_id' => 'limited/model',
        'provider_key_id' => $key->getKey(),
        'window_type' => 'rpm',
        'window_starts_at' => now()->startOfMinute(),
        'window_ends_at' => now()->startOfMinute()->addMinute(),
        'request_count' => 1,
        'token_count' => 0,
    ]);

    $exitCode = Artisan::call('laravel-ai-router:provider:sync', [
        '--key-id' => $key->getKey(),
        '--no-refresh-models' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['results'][0]['api_status'])->toBe('rate_limited')
        ->and($payload['results'][0]['quota']['models'][0]['blocked'])->toBeTrue()
        ->and($payload['results'][0]['quota']['models'][0]['limits']['rpm']['remaining'])->toBe(0);
});

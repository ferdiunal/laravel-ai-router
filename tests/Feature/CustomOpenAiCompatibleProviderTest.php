<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Catalog\ProviderCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterFallback;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterModel;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderDefinition;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderModelCache;
use Ferdiunal\LaravelAiRouter\Routing\ModelRouter;
use Ferdiunal\LaravelAiRouter\Services\ProviderDefinitionManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderKeyManager;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelCacheService;
use Ferdiunal\LaravelAiRouter\Services\ProviderModelSelectionManager;
use Ferdiunal\LaravelAiRouter\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

function migrateLaravelAiRouterForCustomProviderTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('runs the prompt-driven custom provider definition add command without option flags', function () {
    /** @var TestCase $this */
    migrateLaravelAiRouterForCustomProviderTests();

    $this->artisan('laravel-ai-router:provider-definition:add')
        ->expectsOutputToContain('Added custom-openai')
        ->assertSuccessful();

    expect(ProviderCatalog::get('custom-openai'))
        ->toMatchArray([
            'name' => 'Custom OpenAI Proxy',
            'adapter' => 'openai-compatible',
            'base_url' => 'https://example.com/custom/v1',
        ]);
});

it('updates declared model settings on existing custom provider definitions through a command', function () {
    /** @var TestCase $this */
    migrateLaravelAiRouterForCustomProviderTests();

    $definition = app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'models-command-openai',
        name: 'Models Command OpenAI',
        baseUrl: 'https://example.com/models-command/v1',
    );

    $this->artisan('laravel-ai-router:provider-definition:models', [
        '--id' => $definition->getKey(),
        '--models' => 'mimo-v2.5-pro,other-model',
        '--models-endpoint' => 'disabled',
        '--validation-method' => 'chat',
        '--validation-model' => 'mimo-v2.5-pro',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Updated models-command-openai')
        ->assertSuccessful();

    $definition->refresh();

    expect($definition->models_endpoint_enabled)->toBeFalse()
        ->and($definition->validation_method)->toBe('chat')
        ->and($definition->validation_model)->toBe('mimo-v2.5-pro')
        ->and(collect($definition->declared_models)->pluck('model_id')->all())->toBe(['mimo-v2.5-pro', 'other-model'])
        ->and(ProviderCatalog::get('models-command-openai')['declared_models'][0]['model_id'])->toBe('mimo-v2.5-pro');
});

it('routes prompts through config-defined custom OpenAI-compatible providers', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    config()->set('laravel-ai-router.providers.custom.acme-openai', [
        'name' => 'Acme OpenAI Proxy',
        'base_url' => 'https://example.com/acme/v1',
        'headers' => ['X-Proxy-Source' => 'laravel-ai-router'],
        'timeout_ms' => 25_000,
    ]);

    expect(ProviderCatalog::get('acme-openai'))
        ->toMatchArray([
            'name' => 'Acme OpenAI Proxy',
            'adapter' => 'openai-compatible',
            'base_url' => 'https://example.com/acme/v1',
            'timeout_ms' => 25_000,
        ]);

    LaravelAiRouterModel::query()->create([
        'platform' => 'acme-openai',
        'model_id' => 'acme/free-model:free',
        'display_name' => 'Acme Free Model',
        'intelligence_rank' => 10,
        'speed_rank' => 5,
        'budget_label' => 'custom',
        'enabled' => true,
    ]);

    LaravelAiRouterProviderKey::query()->create([
        'platform' => 'acme-openai',
        'label' => 'Primary',
        'key' => 'key-acme-value-123456',
        'status' => 'healthy',
        'enabled' => true,
    ]);

    Http::fake([
        'https://example.com/acme/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl_custom_1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'acme/free-model:free',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Custom çalışıyor'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3, 'total_tokens' => 5],
        ]),
    ]);

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Return a short Turkish answer.';
        }
    };

    $response = $agent->prompt('ping', provider: 'laravel-ai-router', model: 'acme/free-model:free');

    expect((string) $response)->toBe('Custom çalışıyor');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://example.com/acme/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer key-acme-value-123456')
            && $request->hasHeader('X-Proxy-Source', 'laravel-ai-router');
    });
});

it('adds runtime custom OpenAI-compatible providers and caches their routable available models', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'runtime-openai',
        name: 'Runtime OpenAI Proxy',
        baseUrl: 'https://example.com/runtime/v1',
        headers: ['X-Runtime' => 'yes'],
        timeoutMs: 30_000,
    );

    Http::fake([
        'https://example.com/runtime/v1/models' => Http::response([
            'data' => [
                ['id' => 'runtime/free-model:free', 'name' => 'Runtime Free', 'context_length' => 32768, 'supported_parameters' => ['tools']],
                ['id' => 'runtime/paid-model', 'name' => 'Runtime Paid'],
            ],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('runtime-openai', 'key-runtime-value-123456', 'Runtime', refreshModels: true);

    expect($key->platform)->toBe('runtime-openai')
        ->and(LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->orderBy('model_id')->pluck('model_id')->all())
        ->toBe(['runtime/free-model:free', 'runtime/paid-model'])
        ->and(LaravelAiRouterModel::query()->where('platform', 'runtime-openai')->where('model_id', 'runtime/free-model:free')->exists())
        ->toBeTrue();

    $paidModel = LaravelAiRouterModel::query()
        ->where('platform', 'runtime-openai')
        ->where('model_id', 'runtime/paid-model')
        ->firstOrFail();

    expect(LaravelAiRouterFallback::query()
        ->where('laravel_ai_router_model_id', $paidModel->getKey())
        ->value('enabled'))->toBeTrue();
});

it('caches declared custom provider models when the models endpoint is disabled', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'opengateway-test',
        name: 'OpenGateway Test',
        baseUrl: 'https://example.com/gateway/v1',
        modelsEndpointEnabled: false,
        validationMethod: 'chat',
        validationModel: 'mimo-v2.5-pro',
        declaredModels: [[
            'id' => 'mimo-v2.5-pro',
            'name' => 'MIMO v2.5 Pro',
            'budget_label' => 'credits-based',
            'supports_tools' => null,
            'auto_enabled' => true,
        ]],
    );

    Http::fake([
        'https://example.com/gateway/v1/models' => Http::response(['error' => ['message' => 'models unavailable']], 404),
    ]);

    $key = app(ProviderKeyManager::class)->add('opengateway-test', 'key-opengateway-value-123456', 'Gateway', refreshModels: true);
    $cachedModel = LaravelAiRouterProviderModelCache::query()->where('provider_key_id', $key->getKey())->firstOrFail();

    expect($cachedModel->model_id)->toBe('mimo-v2.5-pro')
        ->and($cachedModel->source)->toBe('definition')
        ->and($cachedModel->auto_enabled)->toBeTrue()
        ->and(LaravelAiRouterModel::query()->where('platform', 'opengateway-test')->where('model_id', 'mimo-v2.5-pro')->exists())->toBeTrue()
        ->and(LaravelAiRouterFallback::query()->whereIn('laravel_ai_router_model_id', LaravelAiRouterModel::query()->where('platform', 'opengateway-test')->pluck('id'))->where('enabled', true)->exists())->toBeTrue()
        ->and(app(ProviderModelCacheService::class)->modelIds('opengateway-test', 'Gateway', includeAuto: false))->toBe(['mimo-v2.5-pro']);

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://example.com/gateway/v1/models');
});

it('does not auto select declared models when auto enabled is the string false', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'string-false-opengateway',
        name: 'String False OpenGateway',
        baseUrl: 'https://example.com/gateway/v1',
        modelsEndpointEnabled: false,
        validationMethod: 'chat',
        validationModel: 'mimo-v2.5-pro',
        declaredModels: [[
            'model_id' => 'mimo-v2.5-pro',
            'display_name' => 'MIMO v2.5 Pro',
            'auto_enabled' => 'false',
        ]],
    );

    $key = app(ProviderKeyManager::class)->add('string-false-opengateway', 'key-string-false-value-123456', 'Gateway', refreshModels: true);

    expect(app(ProviderModelSelectionManager::class)->selectedModelIdsForKey($key))->toBe([]);
});

it('routes operator-selected declared models and preserves selection across refresh', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'operator-opengateway',
        name: 'Operator OpenGateway',
        baseUrl: 'https://example.com/gateway/v1',
        modelsEndpointEnabled: false,
        validationMethod: 'chat',
        validationModel: 'mimo-v2.5-pro',
        declaredModels: [[
            'model_id' => 'mimo-v2.5-pro',
            'display_name' => 'MIMO v2.5 Pro',
            'auto_enabled' => false,
        ]],
    );

    $key = app(ProviderKeyManager::class)->add('operator-opengateway', 'key-operator-opengateway-value-123456', 'Gateway', refreshModels: true);

    app(ProviderModelSelectionManager::class)->setSelectedModelIdsForKey($key, ['mimo-v2.5-pro']);

    config()->set('laravel-ai-router.routing.auto_strategy', 'random_provider');

    $route = app(ModelRouter::class)->route('auto');

    app(ProviderModelCacheService::class)->refreshForKey($key->refresh());

    expect($route->modelId)->toBe('mimo-v2.5-pro')
        ->and($route->platform)->toBe('operator-opengateway')
        ->and(app(ProviderModelSelectionManager::class)->selectedModelIdsForKey($key->refresh()))->toBe(['mimo-v2.5-pro']);
});

it('routes exact requests through declared custom provider models without using the models endpoint', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'exact-opengateway',
        name: 'Exact OpenGateway',
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
            'id' => 'chatcmpl_declared_1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'mimo-v2.5-pro',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Declared çalışıyor'],
                'finish_reason' => 'stop',
            ]],
        ]),
    ]);

    app(ProviderKeyManager::class)->add('exact-opengateway', 'key-exact-opengateway-value-123456', 'Gateway', refreshModels: true);

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Return a short Turkish answer.';
        }
    };

    $response = $agent->prompt('ping', provider: 'laravel-ai-router', model: 'mimo-v2.5-pro');

    expect((string) $response)->toBe('Declared çalışıyor');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://example.com/gateway/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer key-exact-opengateway-value-123456')
            && $request['model'] === 'mimo-v2.5-pro';
    });

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://example.com/gateway/v1/models');
});

it('rejects final OpenAI-compatible endpoint URLs in custom provider definitions', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'endpoint-openai',
        name: 'Endpoint OpenAI Proxy',
        baseUrl: 'https://example.com/gateway/v1/chat/completions',
    );
})->throws(ValidationException::class, 'base URL must point to the API root');

it('rejects unsafe or colliding custom provider definitions', function (string $platform, string $baseUrl, string $field) {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: $platform,
        name: 'Unsafe Provider',
        baseUrl: $baseUrl,
    );
})->with([
    ['openrouter', 'https://safe-provider.example.com/v1', 'platform'],
    ['local-proxy', 'http://api.custom-provider.example.com/v1', 'base_url'],
    ['loopback-proxy', 'https://127.0.0.1/v1', 'base_url'],
    ['metadata-proxy', 'https://169.254.169.254/latest', 'base_url'],
    ['trailing-local-proxy', 'https://localhost./v1', 'base_url'],
    ['trailing-internal-proxy', 'https://metadata.google.internal./latest', 'base_url'],
])->throws(ValidationException::class);

it('rejects auth-bearing extra headers on custom provider definitions', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'header-openai',
        name: 'Header OpenAI Proxy',
        baseUrl: 'https://example.com/header/v1',
        headers: ['Authorization' => 'Bearer injected-secret'],
    );
})->throws(ValidationException::class);

it('rejects non-global reserved IP base URLs on custom provider definitions', function (string $baseUrl) {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'reserved-openai',
        name: 'Reserved OpenAI Proxy',
        baseUrl: $baseUrl,
    );
})->with([
    'carrier-grade nat' => ['https://100.64.0.1/v1'],
    'benchmarking network' => ['https://198.18.0.1/v1'],
    'documentation network' => ['https://192.0.2.1/v1'],
    'deprecated 6to4 relay network' => ['https://192.88.99.1/v1'],
    'ipv6 documentation network' => ['https://[2001:db8::1]/v1'],
    'ipv6 teredo network' => ['https://[2001::1]/v1'],
    'ipv6 ietf protocol assignments network' => ['https://[2001:1::1]/v1'],
    'ipv6 dummy prefix' => ['https://[100:0:0:1::1]/v1'],
    'ipv6 documentation prefix 3fff' => ['https://[3fff::1]/v1'],
    'ipv6 segment routing sid block' => ['https://[5f00::1]/v1'],
    'ipv6 orchid network' => ['https://[2001:10::1]/v1'],
    'ipv6 orchidv2 network' => ['https://[2001:20::1]/v1'],
])->throws(ValidationException::class);

it('rejects auth-bearing extra header variants on custom provider definitions', function (array $headers) {
    migrateLaravelAiRouterForCustomProviderTests();

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'variant-header-openai',
        name: 'Variant Header OpenAI Proxy',
        baseUrl: 'https://example.com/variant-header/v1',
        headers: $headers,
    );
})->with([
    'x authorization' => [['X-Authorization' => 'Bearer injected-secret']],
    'x authorization token' => [['X-Authorization-Token' => 'injected-secret']],
    'x auth' => [['X-Auth' => 'injected-secret']],
    'x authz' => [['X-Authz' => 'injected-secret']],
    'x authentication' => [['X-Authentication' => 'injected-secret']],
    'x client token' => [['X-Client-Token' => 'injected-secret']],
    'x amz security token' => [['X-Amz-Security-Token' => 'injected-secret']],
    'x api key no separator' => [['XApiKey' => 'injected-secret']],
    'x auth token no separator' => [['XAuthToken' => 'injected-secret']],
    'x access token no separator' => [['XAccessToken' => 'injected-secret']],
    'x authentication no separator' => [['XAuthentication' => 'injected-secret']],
    'x authz no separator' => [['XAuthz' => 'injected-secret']],
    'x secret no separator' => [['XSecret' => 'injected-secret']],
    'x password no separator' => [['XPassword' => 'injected-secret']],
    'client token no separator' => [['ClientToken' => 'injected-secret']],
    'cookie' => [['Cookie' => 'session=injected-secret']],
])->throws(ValidationException::class);

it('rejects runtime provider definitions that collide with config custom providers', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    config()->set('laravel-ai-router.providers.custom.config-openai', [
        'name' => 'Config OpenAI Proxy',
        'base_url' => 'https://example.com/config/v1',
    ]);

    app(ProviderDefinitionManager::class)->addOpenAiCompatible(
        platform: 'config-openai',
        name: 'Runtime Override Proxy',
        baseUrl: 'https://example.com/runtime-override/v1',
    );
})->throws(ValidationException::class);

it('keeps config custom provider definitions ahead of pre-existing runtime slug collisions', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    config()->set('laravel-ai-router.providers.custom.shadow-openai', [
        'name' => 'Config Shadow Proxy',
        'base_url' => 'https://example.com/config-shadow/v1',
    ]);

    LaravelAiRouterProviderDefinition::query()->create([
        'platform' => 'shadow-openai',
        'name' => 'Runtime Shadow Proxy',
        'adapter' => 'openai-compatible',
        'base_url' => 'https://example.com/runtime-shadow/v1',
        'headers' => [],
        'timeout_ms' => 15_000,
        'requires_placeholder_key' => false,
        'enabled' => true,
    ]);

    expect(ProviderCatalog::get('shadow-openai'))
        ->toMatchArray([
            'name' => 'Config Shadow Proxy',
            'base_url' => 'https://example.com/config-shadow/v1',
        ]);
});

it('ignores config custom provider definitions with auth-bearing extra headers', function () {
    config()->set('laravel-ai-router.providers.custom.bad-header-openai', [
        'name' => 'Bad Header OpenAI Proxy',
        'base_url' => 'https://example.com/bad-header/v1',
        'headers' => ['X-Api-Key' => 'plaintext-secret'],
    ]);

    ProviderCatalog::get('bad-header-openai');
})->throws(InvalidArgumentException::class);

it('deactivates runtime artifacts when a custom provider definition is disabled or removed', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    $definitions = app(ProviderDefinitionManager::class);
    $definition = $definitions->addOpenAiCompatible(
        platform: 'cleanup-openai',
        name: 'Cleanup OpenAI Proxy',
        baseUrl: 'https://example.com/cleanup/v1',
    );

    Http::fake([
        'https://example.com/cleanup/v1/models' => Http::response([
            'data' => [
                ['id' => 'cleanup/free-model:free', 'name' => 'Cleanup Free'],
            ],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('cleanup-openai', 'key-cleanup-value-123456', 'Cleanup', refreshModels: true);

    expect(app(ProviderModelCacheService::class)->modelIds('cleanup-openai', 'Cleanup', includeAuto: false))
        ->toBe(['cleanup/free-model:free']);

    $definitions->setEnabled((int) $definition->getKey(), false);

    expect(LaravelAiRouterProviderKey::query()->whereKey($key->getKey())->value('enabled'))->toBeFalse()
        ->and(LaravelAiRouterProviderModelCache::query()->where('platform', 'cleanup-openai')->where('enabled', true)->exists())->toBeFalse()
        ->and(LaravelAiRouterModel::query()->where('platform', 'cleanup-openai')->where('enabled', true)->exists())->toBeFalse()
        ->and(LaravelAiRouterFallback::query()->whereIn('laravel_ai_router_model_id', LaravelAiRouterModel::query()->where('platform', 'cleanup-openai')->pluck('id'))->where('enabled', true)->exists())->toBeFalse()
        ->and(app(ProviderModelCacheService::class)->modelIds('cleanup-openai', 'Cleanup', includeAuto: false))->toBe([])
        ->and(app(ProviderModelCacheService::class)->firstAvailableModelId())->not->toBe('cleanup/free-model:free');

    $definition = $definitions->addOpenAiCompatible(
        platform: 'remove-openai',
        name: 'Remove OpenAI Proxy',
        baseUrl: 'https://example.com/remove/v1',
    );

    Http::fake([
        'https://example.com/remove/v1/models' => Http::response([
            'data' => [
                ['id' => 'remove/free-model:free', 'name' => 'Remove Free'],
            ],
        ]),
    ]);

    app(ProviderKeyManager::class)->add('remove-openai', 'key-remove-value-123456', 'Remove', refreshModels: true);

    $definitions->remove((int) $definition->getKey());

    expect(LaravelAiRouterProviderKey::query()->where('platform', 'remove-openai')->where('enabled', true)->exists())->toBeFalse()
        ->and(LaravelAiRouterProviderModelCache::query()->where('platform', 'remove-openai')->where('enabled', true)->exists())->toBeFalse()
        ->and(LaravelAiRouterModel::query()->where('platform', 'remove-openai')->where('enabled', true)->exists())->toBeFalse()
        ->and(app(ProviderModelCacheService::class)->modelIds('remove-openai', 'Remove', includeAuto: false))->toBe([]);
});

it('re-enables an existing custom fallback when runtime models are refreshed after a provider is re-enabled', function () {
    migrateLaravelAiRouterForCustomProviderTests();

    $definitions = app(ProviderDefinitionManager::class);
    $definition = $definitions->addOpenAiCompatible(
        platform: 'reactivate-openai',
        name: 'Reactivate OpenAI Proxy',
        baseUrl: 'https://example.com/reactivate/v1',
    );

    Http::fake([
        'https://example.com/reactivate/v1/models' => Http::response([
            'data' => [
                ['id' => 'reactivate/free-model:free', 'name' => 'Reactivate Free'],
            ],
        ]),
    ]);

    $key = app(ProviderKeyManager::class)->add('reactivate-openai', 'key-reactivate-value-123456', 'Reactivate', refreshModels: true);
    $model = LaravelAiRouterModel::query()->where('platform', 'reactivate-openai')->where('model_id', 'reactivate/free-model:free')->firstOrFail();

    $definitions->setEnabled((int) $definition->getKey(), false);
    $definitions->setEnabled((int) $definition->getKey(), true);
    $key->forceFill(['enabled' => true, 'status' => 'healthy'])->save();

    app(ProviderModelCacheService::class)->refreshForKey($key->refresh());

    expect(LaravelAiRouterFallback::query()->where('laravel_ai_router_model_id', $model->getKey())->value('enabled'))->toBeTrue();
});

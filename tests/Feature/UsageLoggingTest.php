<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Catalog\SeedModelCatalog;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Models\AiDevApiRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

function migrateAiDevApiForUsageTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('records provider label, model, token usage, and latency for successful prompts', function () {
    migrateAiDevApiForUsageTests();
    app(SeedModelCatalog::class)->seed();

    AiDevApiProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => 'key-openrouter-value-123456',
        'status' => 'healthy',
        'enabled' => true,
    ]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Merhaba kanka'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 7],
        ]),
    ]);

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Türkçe cevap ver.';
        }
    };

    $agent->prompt('Selam', provider: 'ai-dev-api', model: 'auto');

    $request = AiDevApiRequest::query()->firstOrFail();

    expect($request->platform)->toBe('openrouter')
        ->and($request->provider_label)->toBe('Primary')
        ->and($request->model_id)->toBe('qwen/qwen3-coder:free')
        ->and($request->status)->toBe('success')
        ->and($request->input_tokens)->toBe(3)
        ->and($request->output_tokens)->toBe(4)
        ->and($request->total_tokens)->toBe(7);
});

it('marks routed provider keys invalid when completions return auth failures', function () {
    migrateAiDevApiForUsageTests();
    app(SeedModelCatalog::class)->seed();

    $key = AiDevApiProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => 'key-openrouter-value-invalid',
        'status' => 'healthy',
        'enabled' => true,
    ]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'error' => ['message' => 'quota exceeded for invalid api key'],
        ], 401),
    ]);

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Türkçe cevap ver.';
        }
    };

    $thrown = null;

    try {
        $agent->prompt('Selam', provider: 'ai-dev-api', model: 'auto');
    } catch (RuntimeException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);
    expect($thrown?->getMessage())->toContain('401');

    expect($key->refresh()->status)->toBe('invalid');

    $request = AiDevApiRequest::query()->firstOrFail();
    expect($request->status)->toBe('error');
    expect($request->error_category)->toBe('auth');
});

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
    foreach (glob(__DIR__.'/../../database/migrations/*.php.stub') as $migrationFile) {
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

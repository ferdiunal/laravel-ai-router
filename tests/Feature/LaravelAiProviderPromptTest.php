<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Catalog\SeedModelCatalog;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

function migrateAiDevApiForGatewayTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('prompts a Laravel AI agent through the ai-dev-api provider and auto router', function () {
    migrateAiDevApiForGatewayTests();
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
            'id' => 'chatcmpl_1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'qwen/qwen3-coder:free',
            'choices' => [[
                'index' => 0,
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

    $response = $agent->prompt('Selam', provider: 'ai-dev-api', model: 'auto');

    expect((string) $response)->toBe('Merhaba kanka')
        ->and($response->usage->promptTokens)->toBe(3)
        ->and($response->usage->completionTokens)->toBe(4)
        ->and($response->meta->provider)->toBe('ai-dev-api')
        ->and($response->meta->model)->toBe('qwen/qwen3-coder:free');

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Authorization', 'Bearer key-openrouter-value-123456')
            && $request['messages'][0] === ['role' => 'system', 'content' => 'Türkçe cevap ver.']
            && $request['messages'][1] === ['role' => 'user', 'content' => 'Selam'];
    });
});

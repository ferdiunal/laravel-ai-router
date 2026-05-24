<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Catalog\SeedModelCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;

function migrateLaravelAiRouterForAiHelperTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

function seedLaravelAiRouterKeyForAiHelperTests(): void
{
    LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => 'key-openrouter-helper-123456',
        'status' => 'healthy',
        'enabled' => true,
    ]);
}

it('provides a global ai helper for tinker and application code', function () {
    expect(function_exists('ai'))->toBeTrue();
    expect(ai()->manager())->toBe(app(AiManager::class));
});

it('supports the documented ai using prompt as text chain', function () {
    migrateLaravelAiRouterForAiHelperTests();
    app(SeedModelCatalog::class)->seed();
    seedLaravelAiRouterKeyForAiHelperTests();

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl_helper',
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

    $text = ai()
        ->using('laravel-ai-router', 'auto')
        ->instructions('Türkçe cevap ver.')
        ->prompt('Selam')
        ->asText();

    expect($text)->toBe('Merhaba kanka');

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Authorization', 'Bearer key-openrouter-helper-123456')
            && $request['messages'][0] === ['role' => 'system', 'content' => 'Türkçe cevap ver.']
            && $request['messages'][1] === ['role' => 'user', 'content' => 'Selam'];
    });
});

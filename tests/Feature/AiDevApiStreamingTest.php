<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Catalog\SeedModelCatalog;
use Ferdiunal\AiDevApi\Gateway\AiDevApiTextGateway;
use Ferdiunal\AiDevApi\Models\AiDevApiModel;
use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Models\AiDevApiRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;

function migrateAiDevApiForStreamingTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php.stub') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('streams OpenAI-compatible chat completion chunks through Laravel AI stream events', function () {
    migrateAiDevApiForStreamingTests();
    app(SeedModelCatalog::class)->seed();

    $model = AiDevApiModel::query()
        ->where('platform', 'openrouter')
        ->where('model_id', 'qwen/qwen3-coder:free')
        ->firstOrFail();

    AiDevApiProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => 'key-openrouter-value-123456',
        'status' => 'healthy',
        'enabled' => true,
    ]);

    Http::fake(function (Request $request) use ($model) {
        expect($request->url())->toBe('https://openrouter.ai/api/v1/chat/completions');
        expect($request['model'])->toBe($model->model_id);
        expect($request['stream'])->toBeTrue();

        return Http::response(implode("\n\n", [
            'data: {"id":"chatcmpl-stream","choices":[{"delta":{"content":"Mer"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-stream","choices":[{"delta":{"content":"haba"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-stream","choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":4,"completion_tokens":2,"total_tokens":6}}',
            'data: [DONE]',
        ])."\n\n", 200, ['Content-Type' => 'text/event-stream']);
    });

    config()->set('ai.providers.ai-dev-api', ['driver' => 'ai-dev-api']);
    $provider = app(AiManager::class)->textProvider('ai-dev-api');
    $gateway = app(AiDevApiTextGateway::class);

    $events = iterator_to_array($gateway->streamText(
        'invocation-1',
        $provider,
        $model->model_id,
        'Cevapları kısa tut.',
        [new UserMessage('Selam ver')],
    ));

    expect($events[0])->toBeInstanceOf(StreamStart::class);
    expect($events[1])->toBeInstanceOf(TextStart::class);
    expect($events[2])->toBeInstanceOf(TextDelta::class);
    expect($events[3])->toBeInstanceOf(TextDelta::class);
    expect($events[4])->toBeInstanceOf(TextEnd::class);
    expect($events[5])->toBeInstanceOf(StreamEnd::class);
    expect(collect($events)->whereInstanceOf(TextDelta::class)->pluck('delta')->implode(''))->toBe('Merhaba');

    $requestLog = AiDevApiRequest::query()->firstOrFail();
    expect($requestLog->status)->toBe('success');
    expect($requestLog->platform)->toBe('openrouter');
    expect($requestLog->provider_label)->toBe('Primary');
    expect($requestLog->model_id)->toBe($model->model_id);
    expect($requestLog->input_tokens)->toBe(4);
    expect($requestLog->output_tokens)->toBe(2);
    expect($requestLog->total_tokens)->toBe(6);
});

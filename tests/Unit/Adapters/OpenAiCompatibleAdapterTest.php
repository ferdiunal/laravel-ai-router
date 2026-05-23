<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Adapters\OpenAiCompatibleAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends openai compatible chat completion requests with provider options', function () {
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl_1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'model-a',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3],
        ]),
    ]);

    $adapter = new OpenAiCompatibleAdapter(
        platform: 'example',
        name: 'Example',
        baseUrl: 'https://api.example.com/v1',
        extraHeaders: ['X-Test' => 'yes'],
    );

    $response = $adapter->complete(
        apiKey: 'provider-key',
        messages: [['role' => 'user', 'content' => 'Hi']],
        modelId: 'model-a',
        options: ['temperature' => 0.2, 'max_tokens' => 10, 'top_p' => 0.9],
    );

    expect($response['_routed_via'])->toBe(['platform' => 'example', 'model' => 'model-a']);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.example.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer provider-key')
            && $request->hasHeader('X-Test', 'yes')
            && $request['model'] === 'model-a'
            && $request['messages'][0]['content'] === 'Hi'
            && $request['temperature'] === 0.2
            && $request['max_tokens'] === 10
            && $request['top_p'] === 0.9;
    });
});

it('normalizes compatible provider text responses', function () {
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl_2',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'model-a',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'hello'], ['type' => 'text', 'text' => ' world']],
                ],
                'finish_reason' => 'stop',
            ], [
                'index' => 1,
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'reasoning_content' => 'reasoned answer',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3],
        ]),
    ]);

    $adapter = new OpenAiCompatibleAdapter('example', 'Example', 'https://api.example.com/v1');

    $response = $adapter->complete('key', [['role' => 'user', 'content' => 'Hi']], 'model-a');

    expect($response['choices'][0]['message']['content'])->toBe('hello world')
        ->and($response['choices'][1]['message']['content'])->toBe('reasoned answer');
});

it('keeps null content when compatible response contains tool calls', function () {
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl_3',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'model-a',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'reasoning_content' => 'do not fold',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'lookup', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3],
        ]),
    ]);

    $adapter = new OpenAiCompatibleAdapter('example', 'Example', 'https://api.example.com/v1');

    $response = $adapter->complete('key', [['role' => 'user', 'content' => 'Hi']], 'model-a');

    expect($response['choices'][0]['message']['content'])->toBeNull();
});

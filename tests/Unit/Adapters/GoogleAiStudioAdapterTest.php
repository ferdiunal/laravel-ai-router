<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Adapters\GoogleAiStudioAdapter;
use Ferdiunal\LaravelAiRouter\Exceptions\ProviderAuthenticationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('translates OpenAI-style chat messages to Gemini generateContent requests', function () {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=google-key' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Gemini çalışıyor']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
        ]),
    ]);

    $adapter = new GoogleAiStudioAdapter;
    $response = $adapter->complete(
        apiKey: 'google-key',
        messages: [
            ['role' => 'system', 'content' => 'Kısa cevap ver.'],
            ['role' => 'user', 'content' => 'Selam'],
        ],
        modelId: 'gemini-2.5-flash',
        options: ['temperature' => 0.2, 'max_tokens' => 32, 'top_p' => 0.9],
    );

    expect($adapter->platform())->toBe('google')
        ->and($adapter->name())->toBe('Google AI Studio')
        ->and(data_get($response, 'choices.0.message.content'))->toBe('Gemini çalışıyor')
        ->and(data_get($response, 'choices.0.finish_reason'))->toBe('stop')
        ->and($response['usage'])->toBe(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15])
        ->and($response['_routed_via'])->toBe(['platform' => 'google', 'model' => 'gemini-2.5-flash']);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=google-key'
            && $request['systemInstruction'] === ['parts' => [['text' => 'Kısa cevap ver.']]]
            && $request['contents'] === [['role' => 'user', 'parts' => [['text' => 'Selam']]]]
            && $request['generationConfig'] === ['temperature' => 0.2, 'maxOutputTokens' => 32, 'topP' => 0.9];
    });
});

it('translates Gemini function calls to OpenAI-compatible tool calls', function () {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=google-key' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'functionCall' => [
                        'id' => 'call_123',
                        'name' => 'get_weather',
                        'args' => ['city' => 'İstanbul'],
                    ],
                ]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 3, 'totalTokenCount' => 15],
        ]),
    ]);

    $response = (new GoogleAiStudioAdapter)->complete('google-key', [['role' => 'user', 'content' => 'Hava?']], 'gemini-2.5-flash');

    expect(data_get($response, 'choices.0.finish_reason'))->toBe('tool_calls')
        ->and(data_get($response, 'choices.0.message.content'))->toBeNull()
        ->and(data_get($response, 'choices.0.message.tool_calls.0.id'))->toBe('call_123')
        ->and(data_get($response, 'choices.0.message.tool_calls.0.function.name'))->toBe('get_weather')
        ->and(data_get($response, 'choices.0.message.tool_calls.0.function.arguments'))->toBe('{"city":"İstanbul"}');
});

it('maps OpenAI-style tool definitions and tool choice into Gemini tool config', function () {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=google-key' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'ok']]],
                'finishReason' => 'STOP',
            ]],
        ]),
    ]);

    (new GoogleAiStudioAdapter)->complete(
        apiKey: 'google-key',
        messages: [['role' => 'user', 'content' => 'Hava?']],
        modelId: 'gemini-2.5-flash',
        options: [
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Şehir için hava durumu',
                    'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                ],
            ]],
            'tool_choice' => ['type' => 'function', 'function' => ['name' => 'get_weather']],
        ],
    );

    Http::assertSent(function (Request $request): bool {
        return data_get($request, 'tools.0.functionDeclarations.0.name') === 'get_weather'
            && data_get($request, 'toolConfig.functionCallingConfig.mode') === 'ANY'
            && data_get($request, 'toolConfig.functionCallingConfig.allowedFunctionNames') === ['get_weather'];
    });
});

it('lists Gemini models from the Google model catalog endpoint', function () {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models?key=google-key' => Http::response([
            'models' => [[
                'name' => 'models/gemini-2.5-flash',
                'displayName' => 'Gemini 2.5 Flash',
                'inputTokenLimit' => 1048576,
                'supportedGenerationMethods' => ['generateContent', 'streamGenerateContent'],
            ], [
                'name' => 'models/embedding-001',
                'displayName' => 'Embedding',
                'supportedGenerationMethods' => ['embedContent'],
            ]],
        ]),
    ]);

    expect((new GoogleAiStudioAdapter)->models('google-key'))->toBe([[
        'model_id' => 'gemini-2.5-flash',
        'display_name' => 'Gemini 2.5 Flash',
        'context_window' => 1048576,
        'supports_tools' => null,
        'raw_metadata' => [
            'name' => 'models/gemini-2.5-flash',
            'displayName' => 'Gemini 2.5 Flash',
            'inputTokenLimit' => 1048576,
            'supportedGenerationMethods' => ['generateContent', 'streamGenerateContent'],
        ],
    ]]);
});

it('marks Google auth failures as provider authentication failures', function () {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=bad-key' => Http::response([
            'error' => ['message' => 'API key not valid'],
        ], 401),
    ]);

    (new GoogleAiStudioAdapter)->complete('bad-key', [['role' => 'user', 'content' => 'Hi']], 'gemini-2.5-flash');
})->throws(ProviderAuthenticationException::class);

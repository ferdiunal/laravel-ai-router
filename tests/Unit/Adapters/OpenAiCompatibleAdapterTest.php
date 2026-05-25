<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Adapters\OpenAiCompatibleAdapter;
use Ferdiunal\LaravelAiRouter\Support\ProviderDefinitionValidator;
use Ferdiunal\LaravelAiRouter\Tests\TestCase;
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

it('does not let extra headers override transport safety headers or the provider bearer token', function () {
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl_auth_header',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'model-a',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'hello'],
                'finish_reason' => 'stop',
            ]],
        ]),
    ]);

    $adapter = new OpenAiCompatibleAdapter(
        platform: 'example',
        name: 'Example',
        baseUrl: 'https://api.example.com/v1',
        extraHeaders: ['authorization' => 'Bearer injected-key', 'X-Api-Key' => 'injected-api-key', 'Accept-Encoding' => 'gzip', 'X-Test' => 'yes'],
    );

    $adapter->complete('provider-key', [['role' => 'user', 'content' => 'Hi']], 'model-a');

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Authorization', 'Bearer provider-key')
            && ! $request->hasHeader('Authorization', 'Bearer injected-key')
            && ! $request->hasHeader('X-Api-Key', 'injected-api-key')
            && $request->hasHeader('Accept-Encoding', 'identity')
            && ! $request->hasHeader('Accept-Encoding', 'gzip')
            && $request->hasHeader('X-Test', 'yes');
    });
});

it('validates keys through chat completions when models endpoint validation is disabled', function () {
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl_validate',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'mimo-v2.5-pro',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ]),
        'https://api.example.com/v1/models' => Http::response(['error' => ['message' => 'models unavailable']], 404),
    ]);

    $adapter = new OpenAiCompatibleAdapter(
        platform: 'example',
        name: 'Example',
        baseUrl: 'https://api.example.com/v1',
        validationMethod: 'chat',
        validationModel: 'mimo-v2.5-pro',
    );

    expect($adapter->validateKey('provider-key'))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.example.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer provider-key')
            && $request['model'] === 'mimo-v2.5-pro'
            && $request['messages'][0]['content'] === 'ping'
            && $request['max_tokens'] === 1;
    });
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.example.com/v1/models');
});

it('rejects unsafe custom validation URLs before validating keys', function () {
    Http::fake();

    $adapter = new OpenAiCompatibleAdapter(
        platform: 'custom-validation',
        name: 'Custom Validation',
        baseUrl: 'https://api.example.com/v1',
        enforcePublicBaseUrl: true,
        validateUrl: 'https://localhost./models',
    );

    $adapter->validateKey('provider-key');
})->throws(RuntimeException::class, 'validation URL must be a public HTTPS URL');

it('rejects unsafe custom base URLs before dispatching requests', function () {
    Http::fake();

    $adapter = new OpenAiCompatibleAdapter(
        platform: 'custom-local',
        name: 'Custom Local',
        baseUrl: 'https://localhost./v1',
        enforcePublicBaseUrl: true,
    );

    $adapter->models('provider-key');
})->throws(RuntimeException::class, 'base URL must be a public HTTPS URL');

it('prefers IPv4 DNS pins for dual-stack custom provider hosts', function () {
    /** @var TestCase $this */
    if (! defined('CURLOPT_RESOLVE')) {
        $this->markTestSkipped('cURL resolve pinning is unavailable.');
    }

    $addresses = ProviderDefinitionValidator::publicAddressesForBaseUrl('https://example.com/v1');
    $hasIpv4 = collect($addresses)->contains(fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
    $hasIpv6 = collect($addresses)->contains(fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);

    if (! $hasIpv4 || ! $hasIpv6) {
        $this->markTestSkipped('example.com is not dual-stack in this environment.');
    }

    $adapter = new OpenAiCompatibleAdapter(
        platform: 'dual-stack-custom',
        name: 'Dual Stack Custom',
        baseUrl: 'https://example.com/v1',
        enforcePublicBaseUrl: true,
    );

    $method = new ReflectionMethod($adapter, 'requestOptions');
    $options = $method->invoke($adapter, 'https://example.com/v1/chat/completions');
    /** @var array<int, string> $resolveEntries */
    $resolveEntries = $options['curl'][(int) constant('CURLOPT_RESOLVE')] ?? [];

    expect($resolveEntries)->not->toBeEmpty()
        ->and(collect($resolveEntries)->contains(fn (string $entry): bool => str_contains($entry, '[')))->toBeFalse();
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

it('rejects SSE stream lines that exceed the configured buffer limit', function () {
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response('data: '.str_repeat('x', 64)."\n\n", 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $adapter = new OpenAiCompatibleAdapter(
        platform: 'example',
        name: 'Example',
        baseUrl: 'https://api.example.com/v1',
        maxStreamLineBytes: 16,
    );

    iterator_to_array($adapter->stream('key', [['role' => 'user', 'content' => 'Hi']], 'model-a'));
})->throws(RuntimeException::class, 'SSE line exceeded the configured 16 byte limit.');

it('rejects SSE events that exceed the configured aggregate buffer limit', function () {
    Http::fake([
        'https://api.example.com/v1/chat/completions' => Http::response(implode("\n", [
            'data: 12345678',
            'data: 12345678',
            'data: 12345678',
            'data: 12345678',
            '',
        ]), 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $adapter = new OpenAiCompatibleAdapter(
        platform: 'example',
        name: 'Example',
        baseUrl: 'https://api.example.com/v1',
        maxStreamLineBytes: 64,
        maxStreamEventBytes: 32,
    );

    iterator_to_array($adapter->stream('key', [['role' => 'user', 'content' => 'Hi']], 'model-a'));
})->throws(RuntimeException::class, 'SSE event exceeded the configured 32 byte limit.');

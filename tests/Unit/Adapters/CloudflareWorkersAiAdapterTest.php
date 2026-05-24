<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Adapters\CloudflareWorkersAiAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends Cloudflare Workers AI chat requests with account-scoped URL and token auth', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-cf',
            'object' => 'chat.completion',
            'created' => 123,
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Cloudflare çalışıyor'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3],
        ]),
    ]);

    $adapter = new CloudflareWorkersAiAdapter;
    $response = $adapter->complete(
        apiKey: 'account-123:token-secret',
        messages: [['role' => 'user', 'content' => 'Selam']],
        modelId: '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    );

    expect($adapter->platform())->toBe('cloudflare')
        ->and($adapter->name())->toBe('Cloudflare Workers AI')
        ->and($response['_routed_via'])->toBe(['platform' => 'cloudflare', 'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast']);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer token-secret')
            && $request['model'] === '@cf/meta/llama-3.3-70b-instruct-fp8-fast'
            && $request['messages'][0]['content'] === 'Selam';
    });
});

it('normalizes null assistant content because Cloudflare rejects null tool messages', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-cf-tools',
            'object' => 'chat.completion',
            'created' => 123,
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ]),
    ]);

    (new CloudflareWorkersAiAdapter)->complete(
        apiKey: 'account-123:token-secret',
        messages: [[
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'lookup', 'arguments' => '{}'],
            ]],
        ]],
        modelId: '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    );

    Http::assertSent(fn (Request $request): bool => $request['messages'][0]['content'] === '');
});

it('requires Cloudflare keys to use account_id:api_token format', function () {
    (new CloudflareWorkersAiAdapter)->complete('token-without-account', [['role' => 'user', 'content' => 'Hi']], '@cf/model');
})->throws(RuntimeException::class, 'Cloudflare key must be in format "account_id:api_token"');

it('validates Cloudflare tokens through the token verification endpoint', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['status' => 'active'],
        ]),
    ]);

    expect((new CloudflareWorkersAiAdapter)->validateKey('account-123:token-secret'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/user/tokens/verify'
        && $request->hasHeader('Authorization', 'Bearer token-secret'));
});

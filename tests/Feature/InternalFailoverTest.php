<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Catalog\SeedModelCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterRateWindow;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Streaming\Events\TextDelta;

function migrateLaravelAiRouterForInternalFailoverTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

function seedLaravelAiRouterKeyForInternalFailover(string $label): LaravelAiRouterProviderKey
{
    return LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => $label,
        'key' => 'key-openrouter-value-'.strtolower($label),
        'status' => 'healthy',
        'enabled' => true,
    ]);
}

function internalFailoverAgent(): Agent
{
    return new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Kısa Türkçe cevap ver.';
        }
    };
}

it('retries retryable non-stream provider failures inside one laravel ai router provider', function () {
    migrateLaravelAiRouterForInternalFailoverTests();
    app(SeedModelCatalog::class)->seed();

    $primary = seedLaravelAiRouterKeyForInternalFailover('Primary');
    $backup = seedLaravelAiRouterKeyForInternalFailover('Backup');

    config()->set('laravel-ai-router.routing.max_attempts', 2);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'rate limit exceeded']], 429)
            ->push([
                'id' => 'chatcmpl-internal-failover-success',
                'object' => 'chat.completion',
                'created' => 2,
                'model' => 'qwen/qwen3-coder:free',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Yedek çalıştı'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3, 'total_tokens' => 5],
            ]),
    ]);

    $response = internalFailoverAgent()->prompt('Selam', provider: 'laravel-ai-router', model: 'auto');

    expect((string) $response)->toBe('Yedek çalıştı');
    Http::assertSentCount(2);

    $sentKeys = [];
    Http::assertSent(function (Request $request) use (&$sentKeys): bool {
        $sentKeys[] = $request->header('Authorization')[0] ?? null;

        return true;
    });

    expect($sentKeys)->toBe([
        'Bearer key-openrouter-value-primary',
        'Bearer key-openrouter-value-backup',
    ]);

    $requests = LaravelAiRouterRequest::query()->orderBy('id')->get();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]->status)->toBe('error')
        ->and($requests[0]->provider_key_id)->toBe($primary->getKey())
        ->and($requests[0]->error_category)->toBe('rate_limit')
        ->and($requests[0]->attempt)->toBe(1)
        ->and($requests[1]->status)->toBe('success')
        ->and($requests[1]->provider_key_id)->toBe($backup->getKey())
        ->and($requests[1]->attempt)->toBe(2);

    expect(LaravelAiRouterRateWindow::query()
        ->where('provider_key_id', $primary->getKey())
        ->where('window_type', 'cooldown')
        ->exists())->toBeTrue();
});

it('respects max attempts when every internal route keeps failing', function () {
    migrateLaravelAiRouterForInternalFailoverTests();
    app(SeedModelCatalog::class)->seed();

    seedLaravelAiRouterKeyForInternalFailover('Primary');
    seedLaravelAiRouterKeyForInternalFailover('Backup');
    seedLaravelAiRouterKeyForInternalFailover('Tertiary');

    config()->set('laravel-ai-router.routing.max_attempts', 2);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'rate limit one']], 429)
            ->push(['error' => ['message' => 'rate limit two']], 429)
            ->push([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Denememeli'],
                    'finish_reason' => 'stop',
                ]],
            ]),
    ]);

    expect(fn () => internalFailoverAgent()->prompt('Selam', provider: 'laravel-ai-router', model: 'auto'))
        ->toThrow(RateLimitedException::class);

    Http::assertSentCount(2);

    expect(LaravelAiRouterRequest::query()->orderBy('id')->pluck('attempt')->all())->toBe([1, 2]);
    expect(LaravelAiRouterRequest::query()->where('status', 'success')->exists())->toBeFalse();
});

it('invalidates auth-failed keys and retries the next internal provider key', function () {
    migrateLaravelAiRouterForInternalFailoverTests();
    app(SeedModelCatalog::class)->seed();

    $primary = seedLaravelAiRouterKeyForInternalFailover('Primary');
    $backup = seedLaravelAiRouterKeyForInternalFailover('Backup');

    config()->set('laravel-ai-router.routing.max_attempts', 2);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'invalid api key']], 401)
            ->push([
                'id' => 'chatcmpl-internal-auth-failover-success',
                'object' => 'chat.completion',
                'created' => 2,
                'model' => 'qwen/qwen3-coder:free',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Auth yedeği çalıştı'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 4, 'total_tokens' => 6],
            ]),
    ]);

    $response = internalFailoverAgent()->prompt('Selam', provider: 'laravel-ai-router', model: 'auto');

    expect((string) $response)->toBe('Auth yedeği çalıştı')
        ->and($primary->refresh()->status)->toBe('invalid')
        ->and($backup->refresh()->status)->toBe('healthy');

    $requests = LaravelAiRouterRequest::query()->orderBy('id')->get();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]->error_category)->toBe('auth')
        ->and($requests[0]->attempt)->toBe(1)
        ->and($requests[1]->status)->toBe('success')
        ->and($requests[1]->attempt)->toBe(2);
});

it('retries streaming provider failures before emitting stream events', function () {
    migrateLaravelAiRouterForInternalFailoverTests();
    app(SeedModelCatalog::class)->seed();

    seedLaravelAiRouterKeyForInternalFailover('Primary');
    seedLaravelAiRouterKeyForInternalFailover('Backup');

    config()->set('laravel-ai-router.routing.max_attempts', 2);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'rate limit exceeded']], 429)
            ->push(implode("\n", [
                'data: {"id":"chatcmpl-stream-internal-failover","choices":[{"delta":{"content":"Stream"},"finish_reason":null}]}',
                '',
                'data: {"id":"chatcmpl-stream-internal-failover","choices":[{"delta":{"content":" yedeği"},"finish_reason":null}]}',
                '',
                'data: {"id":"chatcmpl-stream-internal-failover","choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":2,"completion_tokens":2,"total_tokens":4}}',
                '',
                'data: [DONE]',
                '',
            ]), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $events = iterator_to_array(internalFailoverAgent()->stream('Selam', provider: 'laravel-ai-router', model: 'auto'));

    expect(collect($events)->whereInstanceOf(TextDelta::class)->pluck('delta')->implode(''))->toBe('Stream yedeği');
    Http::assertSentCount(2);

    expect(LaravelAiRouterRequest::query()->orderBy('id')->pluck('attempt')->all())->toBe([1, 2]);
});

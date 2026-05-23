<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Catalog\SeedModelCatalog;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterRateWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request as ToolRequest;

function migrateLaravelAiRouterForSdkParityTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

function seedLaravelAiRouterKeyForSdkParity(string $label = 'Primary'): LaravelAiRouterProviderKey
{
    return LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => $label,
        'key' => 'key-openrouter-value-'.strtolower($label),
        'status' => 'healthy',
        'enabled' => true,
    ]);
}

it('returns Laravel AI structured agent responses when a schema is defined', function () {
    migrateLaravelAiRouterForSdkParityTests();
    app(SeedModelCatalog::class)->seed();
    seedLaravelAiRouterKeyForSdkParity();

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-structured',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'qwen/qwen3-coder:free',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => '{"summary":"Hazır","score":9}'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 6, 'total_tokens' => 11],
        ]),
    ]);

    $agent = new class implements Agent, HasStructuredOutput
    {
        use Promptable;

        public function instructions(): string
        {
            return 'JSON cevap ver.';
        }

        /** @return array<string, Type> */
        public function schema(JsonSchema $schema): array
        {
            return [
                'summary' => $schema->string()->required(),
                'score' => $schema->integer()->required(),
            ];
        }
    };

    $response = $agent->prompt('Durumu özetle', provider: 'laravel-ai-router', model: 'auto');

    expect($response)->toBeInstanceOf(StructuredAgentResponse::class)
        ->and($response['summary'])->toBe('Hazır')
        ->and($response['score'])->toBe(9)
        ->and($response->usage->promptTokens)->toBe(5)
        ->and($response->usage->completionTokens)->toBe(6)
        ->and($response->meta->provider)->toBe('laravel-ai-router')
        ->and($response->meta->model)->toBe('qwen/qwen3-coder:free');

    Http::assertSent(fn (Request $request): bool => $request['response_format'] === ['type' => 'json_object']);
});

it('executes OpenAI-compatible tool calls and continues with tool results', function () {
    migrateLaravelAiRouterForSdkParityTests();
    app(SeedModelCatalog::class)->seed();
    seedLaravelAiRouterKeyForSdkParity();

    $tool = new class implements Tool
    {
        public int $calls = 0;

        public function name(): string
        {
            return 'lookup_order';
        }

        public function description(): string
        {
            return 'Look up an order total.';
        }

        public function handle(ToolRequest $request): string
        {
            $this->calls++;

            return 'Order '.$request['order_id'].' total is 42 TRY';
        }

        /** @return array<string, Type> */
        public function schema(JsonSchema $schema): array
        {
            return ['order_id' => $schema->string()->required()];
        }
    };

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push([
                'id' => 'chatcmpl-tool-1',
                'object' => 'chat.completion',
                'created' => 1,
                'model' => 'qwen/qwen3-coder:free',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'lookup_order',
                                'arguments' => '{"order_id":"A-100"}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10],
            ])
            ->push([
                'id' => 'chatcmpl-tool-2',
                'object' => 'chat.completion',
                'created' => 2,
                'model' => 'qwen/qwen3-coder:free',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Sipariş toplamı 42 TRY.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 5, 'total_tokens' => 14],
            ]),
    ]);

    $agent = new class($tool) implements Agent, HasTools
    {
        use Promptable;

        public function __construct(private readonly Tool $tool) {}

        public function instructions(): string
        {
            return 'Gerektiğinde araç kullan.';
        }

        /** @return iterable<int, Tool> */
        public function tools(): iterable
        {
            return [$this->tool];
        }
    };

    $response = $agent->prompt('A-100 sipariş toplamı nedir?', provider: 'laravel-ai-router', model: 'auto');

    expect((string) $response)->toBe('Sipariş toplamı 42 TRY.')
        ->and($tool->calls)->toBe(1)
        ->and($response->usage->promptTokens)->toBe(16)
        ->and($response->usage->completionTokens)->toBe(8)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->steps)->toHaveCount(2);

    expect((int) LaravelAiRouterRateWindow::query()->where('window_type', 'rpm')->value('request_count'))->toBe(2);

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request): bool {
        if (! isset($request['tools'])) {
            return false;
        }

        return data_get($request['tools'], '0.function.name') === 'lookup_order'
            && data_get($request['tools'], '0.function.parameters.properties.order_id.type') === 'string';
    });
    Http::assertSent(function (Request $request): bool {
        $messages = $request['messages'];

        if (! is_array($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            if (($message['role'] ?? null) === 'tool'
                && ($message['tool_call_id'] ?? null) === 'call_1'
                && str_contains((string) ($message['content'] ?? ''), '42 TRY')) {
                return true;
            }
        }

        return false;
    });
});

it('maps retryable provider failures to Laravel AI failover exceptions', function () {
    migrateLaravelAiRouterForSdkParityTests();
    app(SeedModelCatalog::class)->seed();
    seedLaravelAiRouterKeyForSdkParity('Primary');
    seedLaravelAiRouterKeyForSdkParity('Backup');

    config()->set('ai.providers.laravel-ai-router-backup', ['driver' => 'laravel-ai-router']);
    config()->set('laravel-ai-router.routing.max_attempts', 1);

    Event::fake([AgentFailedOver::class]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'rate limit exceeded']], 429)
            ->push([
                'id' => 'chatcmpl-failover-success',
                'object' => 'chat.completion',
                'created' => 2,
                'model' => 'qwen/qwen3-coder:free',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Failover çalıştı'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3, 'total_tokens' => 5],
            ]),
    ]);

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Kısa cevap ver.';
        }
    };

    $response = $agent->prompt(
        'Selam',
        provider: ['laravel-ai-router' => 'auto', 'laravel-ai-router-backup' => 'auto'],
    );

    expect((string) $response)->toBe('Failover çalıştı');

    Event::assertDispatched(AgentFailedOver::class);
    Http::assertSentCount(2);
});

it('passes non-stream prompt timeouts to the upstream provider request', function () {
    migrateLaravelAiRouterForSdkParityTests();
    app(SeedModelCatalog::class)->seed();
    seedLaravelAiRouterKeyForSdkParity();

    Http::fake(function (Request $request, array $options) {
        expect($request->url())->toBe('https://openrouter.ai/api/v1/chat/completions');
        expect($options['timeout'])->toBe(17.0);

        return Http::response([
            'id' => 'chatcmpl-timeout',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'qwen/qwen3-coder:free',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Tamam'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1, 'total_tokens' => 3],
        ]);
    });

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Kısa cevap ver.';
        }
    };

    $response = $agent->prompt('Selam', provider: 'laravel-ai-router', model: 'auto', timeout: 17);

    expect((string) $response)->toBe('Tamam');
});

it('can fail over streaming prompts before emitting stream events', function () {
    migrateLaravelAiRouterForSdkParityTests();
    app(SeedModelCatalog::class)->seed();
    seedLaravelAiRouterKeyForSdkParity('Primary');
    seedLaravelAiRouterKeyForSdkParity('Backup');

    config()->set('ai.providers.laravel-ai-router-backup', ['driver' => 'laravel-ai-router']);
    config()->set('laravel-ai-router.routing.max_attempts', 1);

    Event::fake([AgentFailedOver::class]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'rate limit exceeded']], 429)
            ->push(implode("\n", [
                'data: {"id":"chatcmpl-stream-failover","choices":[{"delta":{"content":"Yedek"},"finish_reason":null}]}',
                '',
                'data: {"id":"chatcmpl-stream-failover","choices":[{"delta":{"content":" çalıştı"},"finish_reason":null}]}',
                '',
                'data: {"id":"chatcmpl-stream-failover","choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":2,"completion_tokens":2,"total_tokens":4}}',
                '',
                'data: [DONE]',
                '',
            ]), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Kısa cevap ver.';
        }
    };

    $events = iterator_to_array($agent->stream(
        'Selam',
        provider: ['laravel-ai-router' => 'auto', 'laravel-ai-router-backup' => 'auto'],
    ));

    expect(collect($events)->whereInstanceOf(TextDelta::class)->pluck('delta')->implode(''))->toBe('Yedek çalıştı');

    Event::assertDispatched(AgentFailedOver::class);
    Http::assertSentCount(2);
});

it('maps generic payment required responses to insufficient credit failover', function () {
    migrateLaravelAiRouterForSdkParityTests();
    app(SeedModelCatalog::class)->seed();
    seedLaravelAiRouterKeyForSdkParity('Primary');
    seedLaravelAiRouterKeyForSdkParity('Backup');

    config()->set('ai.providers.laravel-ai-router-backup', ['driver' => 'laravel-ai-router']);
    config()->set('laravel-ai-router.routing.max_attempts', 1);

    Event::fake([AgentFailedOver::class]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'Payment Required']], 402)
            ->push([
                'id' => 'chatcmpl-payment-failover-success',
                'object' => 'chat.completion',
                'created' => 2,
                'model' => 'qwen/qwen3-coder:free',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Yedek ödeme sonrası çalıştı'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 4, 'total_tokens' => 6],
            ]),
    ]);

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Kısa cevap ver.';
        }
    };

    $response = $agent->prompt(
        'Selam',
        provider: ['laravel-ai-router' => 'auto', 'laravel-ai-router-backup' => 'auto'],
    );

    expect((string) $response)->toBe('Yedek ödeme sonrası çalıştı');

    Event::assertDispatched(AgentFailedOver::class);
    Http::assertSentCount(2);
});

it('maps generic provider overload responses to failover', function () {
    migrateLaravelAiRouterForSdkParityTests();
    app(SeedModelCatalog::class)->seed();
    seedLaravelAiRouterKeyForSdkParity('Primary');
    seedLaravelAiRouterKeyForSdkParity('Backup');

    config()->set('ai.providers.laravel-ai-router-backup', ['driver' => 'laravel-ai-router']);
    config()->set('laravel-ai-router.routing.max_attempts', 1);

    Event::fake([AgentFailedOver::class]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'Service Unavailable']], 503)
            ->push([
                'id' => 'chatcmpl-overload-failover-success',
                'object' => 'chat.completion',
                'created' => 2,
                'model' => 'qwen/qwen3-coder:free',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Yedek overload sonrası çalıştı'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 4, 'total_tokens' => 6],
            ]),
    ]);

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Kısa cevap ver.';
        }
    };

    $response = $agent->prompt(
        'Selam',
        provider: ['laravel-ai-router' => 'auto', 'laravel-ai-router-backup' => 'auto'],
    );

    expect((string) $response)->toBe('Yedek overload sonrası çalıştı');

    Event::assertDispatched(AgentFailedOver::class);
    Http::assertSentCount(2);
});

it('maps connection timed out failures to provider overload failover', function () {
    migrateLaravelAiRouterForSdkParityTests();
    app(SeedModelCatalog::class)->seed();
    seedLaravelAiRouterKeyForSdkParity('Primary');
    seedLaravelAiRouterKeyForSdkParity('Backup');

    config()->set('ai.providers.laravel-ai-router-backup', ['driver' => 'laravel-ai-router']);
    config()->set('laravel-ai-router.routing.max_attempts', 1);

    Event::fake([AgentFailedOver::class]);

    $attempt = 0;

    Http::fake(function () use (&$attempt) {
        if ($attempt++ === 0) {
            throw new ConnectionException('Operation timed out after 10000 milliseconds');
        }

        return Http::response([
            'id' => 'chatcmpl-timeout-failover-success',
            'object' => 'chat.completion',
            'created' => 2,
            'model' => 'qwen/qwen3-coder:free',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Yedek timeout sonrası çalıştı'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 4, 'total_tokens' => 6],
        ]);
    });

    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Kısa cevap ver.';
        }
    };

    $response = $agent->prompt(
        'Selam',
        provider: ['laravel-ai-router' => 'auto', 'laravel-ai-router-backup' => 'auto'],
    );

    expect((string) $response)->toBe('Yedek timeout sonrası çalıştı');

    Event::assertDispatched(AgentFailedOver::class);
    expect($attempt)->toBe(2);
});

<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Gateway;

use Ferdiunal\LaravelAiRouter\Adapters\ProviderAdapterRegistry;
use Ferdiunal\LaravelAiRouter\Exceptions\ProviderAuthenticationException;
use Ferdiunal\LaravelAiRouter\Routing\ModelRouter;
use Ferdiunal\LaravelAiRouter\Routing\RateLimitWindowRepository;
use Ferdiunal\LaravelAiRouter\Routing\RouteResult;
use Ferdiunal\LaravelAiRouter\Services\UsageLogger;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider as BaseProvider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Adapts Laravel AI text generation and streaming calls to routed OpenAI-compatible provider keys.
 */
final class LaravelAiRouterTextGateway implements Gateway
{
    use InvokesTools;

    /**
     * Initialize the gateway with routing, adapter dispatch, rate-limit, and usage logging services.
     */
    public function __construct(
        private readonly ModelRouter $router,
        private readonly ProviderAdapterRegistry $adapters,
        private readonly RateLimitWindowRepository $rateLimits,
        private readonly UsageLogger $usageLogger,
    ) {}

    /**
     * Route a Laravel AI text request, call the selected upstream provider, execute supported tool loops, and persist usage telemetry.
     *
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $startedAt = microtime(true);
        $payloadMessages = $this->mapMessages($instructions, $messages);
        $estimatedTokens = $this->estimateTokens($payloadMessages, $this->maxOutputTokens($options));
        $route = null;

        try {
            $route = $this->router->route($model, $estimatedTokens, requiresTools: $tools !== []);
            $this->rateLimits->recordRequest($route->platform, $route->modelId, $route->keyId);

            $data = $this->adapters
                ->for($route->platform)
                ->complete($route->apiKey, $payloadMessages, $route->modelId, $this->mapOptions($provider, $options, $schema, $tools), $timeout);

            $response = $this->processCompletionResponse(
                data: $data,
                provider: $provider,
                route: $route,
                tools: $tools,
                schema: $schema,
                options: $options,
                payloadMessages: $payloadMessages,
                steps: new Collection,
                responseMessages: new Collection,
                timeout: $timeout,
            );

            $inputTokens = $response->usage->promptTokens;
            $outputTokens = $response->usage->completionTokens;
            $totalTokens = $inputTokens + $outputTokens;

            $this->rateLimits->recordTokens($route->platform, $route->modelId, $route->keyId, $totalTokens);
            $this->router->recordSuccess($route);
            $this->usageLogger->success($route, $inputTokens, $outputTokens, $this->latencyMs($startedAt));

            return $response;
        } catch (Throwable $exception) {
            $category = $this->errorCategory($exception);
            $this->usageLogger->error($route, $exception, $category, $this->latencyMs($startedAt));

            if ($route instanceof RouteResult && $category === 'auth') {
                $this->router->recordAuthFailure($route);
            }

            if ($route instanceof RouteResult && $this->shouldCooldownRoute($category)) {
                $this->rateLimits->setCooldown($route->platform, $route->modelId, $route->keyId, (int) config('laravel-ai-router.routing.cooldown_seconds', 120));
                $this->router->recordRetryableFailure($route);
            }

            throw $this->mapExceptionForSdk($exception, $provider, $category);
        }
    }

    /**
     * Route a Laravel AI streaming request, translate provider SSE chunks to Laravel AI stream events, and persist usage telemetry.
     *
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        if ($tools !== []) {
            throw new LogicException('Laravel AI Router does not support streaming tool calls yet.');
        }

        $startedAt = microtime(true);
        $payloadMessages = $this->mapMessages($instructions, $messages);
        $estimatedTokens = $this->estimateTokens($payloadMessages, $this->maxOutputTokens($options));
        $route = null;
        $messageId = $this->eventId();
        $streamStarted = false;
        $textStarted = false;
        $textEnded = false;
        $finishReason = 'stop';
        $currentText = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $totalTokens = 0;

        try {
            $route = $this->router->route($model, $estimatedTokens);
            $this->rateLimits->recordRequest($route->platform, $route->modelId, $route->keyId);

            foreach ($this->adapters->for($route->platform)->stream($route->apiKey, $payloadMessages, $route->modelId, $this->mapOptions($provider, $options, $schema), $timeout) as $chunk) {
                if (isset($chunk['error'])) {
                    throw new RuntimeException((string) data_get($chunk, 'error.message', 'Laravel AI Router streaming error.'));
                }

                if (! $streamStarted) {
                    $streamStarted = true;

                    yield (new StreamStart(
                        $this->eventId(),
                        $this->providerName($provider),
                        $route->modelId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $usage = $this->streamUsage($chunk);
                if ($usage !== null) {
                    $inputTokens = $usage['input_tokens'];
                    $outputTokens = $usage['output_tokens'];
                    $totalTokens = $usage['total_tokens'];
                }

                $delta = $this->streamDelta($chunk);
                if ($delta !== '') {
                    if (! $textStarted) {
                        $textStarted = true;

                        yield (new TextStart($this->eventId(), $messageId, time()))->withInvocationId($invocationId);
                    }

                    $currentText .= $delta;

                    yield (new TextDelta($this->eventId(), $messageId, $delta, time()))->withInvocationId($invocationId);
                }

                $chunkFinishReason = $this->streamFinishReason($chunk);
                if ($chunkFinishReason !== null) {
                    $finishReason = $chunkFinishReason;

                    if ($textStarted && ! $textEnded) {
                        $textEnded = true;

                        yield (new TextEnd($this->eventId(), $messageId, time()))->withInvocationId($invocationId);
                    }
                }
            }

            if ($textStarted && ! $textEnded) {
                yield (new TextEnd($this->eventId(), $messageId, time()))->withInvocationId($invocationId);
            }

            if ($inputTokens === 0 && $outputTokens === 0) {
                $inputTokens = $this->estimateTokens($payloadMessages, 0);
                $outputTokens = max(0, (int) ceil(strlen($currentText) / 4));
                $totalTokens = $inputTokens + $outputTokens;
            }

            $this->rateLimits->recordTokens($route->platform, $route->modelId, $route->keyId, $totalTokens);
            $this->router->recordSuccess($route);
            $this->usageLogger->success($route, $inputTokens, $outputTokens, $this->latencyMs($startedAt));

            yield (new StreamEnd(
                $this->eventId(),
                $finishReason,
                new Usage($inputTokens, $outputTokens),
                time(),
            ))->withInvocationId($invocationId);
        } catch (Throwable $exception) {
            $category = $this->errorCategory($exception);
            $this->usageLogger->error($route, $exception, $category, $this->latencyMs($startedAt));

            if ($route instanceof RouteResult && $category === 'auth') {
                $this->router->recordAuthFailure($route);
            }

            if ($route instanceof RouteResult && $this->shouldCooldownRoute($category)) {
                $this->rateLimits->setCooldown($route->platform, $route->modelId, $route->keyId, (int) config('laravel-ai-router.routing.cooldown_seconds', 120));
                $this->router->recordRetryableFailure($route);
            }

            throw $this->mapExceptionForSdk($exception, $provider, $category);
        }
    }

    /**
     * Reject audio generation because this package currently implements only Laravel AI text provider capabilities.
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        throw new LogicException('Laravel AI Router does not support audio generation.');
    }

    /**
     * Reject embedding generation because this package currently implements only Laravel AI text provider capabilities.
     */
    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
    {
        throw new LogicException('Laravel AI Router does not support embeddings.');
    }

    /**
     * Reject image generation because this package currently implements only Laravel AI text provider capabilities.
     *
     * @param  array<Image>  $attachments
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        throw new LogicException('Laravel AI Router does not support image generation.');
    }

    /**
     * Reject transcription generation because this package currently implements only Laravel AI text provider capabilities.
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        throw new LogicException('Laravel AI Router does not support transcription generation.');
    }

    /**
     * Convert Laravel AI instructions and message objects into OpenAI-compatible chat message arrays.
     *
     * @param  array<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function mapMessages(?string $instructions, array $messages): array
    {
        $mapped = [];

        if (filled($instructions)) {
            $mapped[] = ['role' => 'system', 'content' => $instructions];
        }

        foreach ($messages as $message) {
            if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
                throw new LogicException('Laravel AI Router does not support file or image attachments yet.');
            }

            if ($message instanceof AssistantMessage) {
                $mapped[] = $this->assistantMessagePayload($message);

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $mapped = [
                    ...$mapped,
                    ...$this->toolResultPayloads($message->toolResults->all()),
                ];

                continue;
            }

            $role = $message->role->value;
            if ($role === 'tool_result') {
                $role = 'tool';
            }

            $mapped[] = ['role' => $role, 'content' => (string) $message->content];
        }

        return $mapped;
    }

    /**
     * Convert Laravel AI generation options, structured-output schema, and tools into OpenAI-compatible request options.
     *
     * @param  array<string, Type>|null  $schema
     * @param  array<int, Tool>  $tools
     * @return array<string, mixed>
     */
    private function mapOptions(TextProvider $provider, ?TextGenerationOptions $options, ?array $schema, array $tools = []): array
    {
        if ($options === null) {
            $mapped = [];
            $providerOptions = [];
        } else {
            $mapped = Arr::whereNotNull([
                'temperature' => $options->temperature,
                'max_tokens' => $options->maxTokens,
                'top_p' => $options->topP,
            ]);

            $providerOptions = $options->providerOptions($this->providerDriver($provider)) ?? [];
        }
        $mapped = [...$mapped, ...$providerOptions];

        if (! empty($schema)) {
            $mapped['response_format'] = ['type' => 'json_object'];
        }

        if ($tools !== []) {
            $mapped['tools'] = $this->mapTools($tools);
            $mapped['tool_choice'] = 'auto';
        }

        return $mapped;
    }

    /**
     * Normalize an upstream completion response into Laravel AI text or structured response objects.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, Tool>  $tools
     * @param  array<string, Type>|null  $schema
     * @param  array<int, array<string, mixed>>  $payloadMessages
     * @param  Collection<int, Step>  $steps
     * @param  Collection<int, Message>  $responseMessages
     */
    private function processCompletionResponse(
        array $data,
        TextProvider $provider,
        RouteResult $route,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        array $payloadMessages,
        Collection $steps,
        Collection $responseMessages,
        ?int $timeout,
    ): TextResponse {
        $text = $this->contentFromResponse($data);
        $usage = $this->usageFromResponse($data);
        $finishReason = $this->finishReasonFromResponse($data);
        $toolCalls = $this->toolCallsFromResponse($data);
        $meta = new Meta($this->providerName($provider), $route->modelId);

        $step = new Step($text, $toolCalls, [], $finishReason, $usage, $meta);
        $steps->push($step);

        $assistantMessage = new AssistantMessage($text, new Collection($toolCalls));
        $responseMessages->push($assistantMessage);

        if ($finishReason === FinishReason::ToolCalls && $toolCalls !== [] && $steps->count() < $this->maxToolSteps($tools, $options)) {
            $toolResults = $this->executeToolCalls($toolCalls, $tools);

            if ($toolResults !== []) {
                $steps->pop();
                $steps->push(new Step($text, $toolCalls, $toolResults, $finishReason, $usage, $meta));

                $toolResultMessage = new ToolResultMessage(new Collection($toolResults));
                $responseMessages->push($toolResultMessage);

                $nextPayloadMessages = [
                    ...$payloadMessages,
                    $this->assistantMessagePayload($assistantMessage),
                    ...$this->toolResultPayloads($toolResults),
                ];

                $this->rateLimits->recordRequest($route->platform, $route->modelId, $route->keyId);

                $nextData = $this->adapters
                    ->for($route->platform)
                    ->complete($route->apiKey, $nextPayloadMessages, $route->modelId, $this->mapOptions($provider, $options, $schema, $tools), $timeout);

                return $this->processCompletionResponse(
                    data: $nextData,
                    provider: $provider,
                    route: $route,
                    tools: $tools,
                    schema: $schema,
                    options: $options,
                    payloadMessages: $nextPayloadMessages,
                    steps: $steps,
                    responseMessages: $responseMessages,
                    timeout: $timeout,
                );
            }
        }

        $combinedUsage = $this->combineUsage($steps);
        $allToolCalls = $steps->flatMap(fn (Step $step): array => $step->toolCalls);
        $allToolResults = $steps->flatMap(fn (Step $step): array => $step->toolResults);

        if ($schema !== null && $schema !== []) {
            $structuredData = json_decode($text, true);

            return (new StructuredTextResponse(
                is_array($structuredData) ? $structuredData : [],
                $text,
                $combinedUsage,
                $meta,
            ))->withToolCallsAndResults(
                toolCalls: $allToolCalls,
                toolResults: $allToolResults,
            )->withSteps($steps);
        }

        return (new TextResponse(
            $text,
            $combinedUsage,
            $meta,
        ))->withMessages($responseMessages)->withSteps($steps);
    }

    /**
     * Execute provider-requested tool calls through Laravel AI and return tool result messages for the follow-up request.
     *
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, Tool>  $tools
     * @return array<int, ToolResult>
     */
    private function executeToolCalls(array $toolCalls, array $tools): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            if (! $tool instanceof Tool) {
                continue;
            }

            $results[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $this->executeTool($tool, $toolCall->arguments),
                $toolCall->resultId,
            );
        }

        return $results;
    }

    /**
     * Resolve the bounded number of provider tool-call continuation steps allowed for one request.
     *
     * @param  array<int, Tool>  $tools
     */
    private function maxToolSteps(array $tools, ?TextGenerationOptions $options): int
    {
        if ($options instanceof TextGenerationOptions && $options->maxSteps !== null) {
            return $options->maxSteps;
        }

        return max(1, (int) ceil(count($tools) * 1.5));
    }

    /**
     * Convert Laravel AI tools into OpenAI-compatible tool definitions.
     *
     * @param  array<int, Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function mapTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                throw new LogicException('Laravel AI Router does not support provider-native tools yet.');
            }

            if ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Convert one Laravel AI tool definition into an OpenAI-compatible function tool payload.
     *
     * @return array<string, mixed>
     */
    private function mapTool(Tool $tool): array
    {
        $schemaArray = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

        return [
            'type' => 'function',
            'function' => [
                'name' => ToolNameResolver::resolve($tool),
                'description' => (string) $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $schemaArray['properties'] ?? (object) [],
                    'required' => $schemaArray['required'] ?? [],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Extract normalized tool-call descriptors from an OpenAI-compatible completion response.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, ToolCall>
     */
    private function toolCallsFromResponse(array $data): array
    {
        $rawToolCalls = data_get($data, 'choices.0.message.tool_calls', []);

        if (! is_array($rawToolCalls)) {
            return [];
        }

        return collect($rawToolCalls)
            ->filter(fn (mixed $toolCall): bool => is_array($toolCall))
            ->map(fn (array $toolCall): ToolCall => new ToolCall(
                (string) ($toolCall['id'] ?? ''),
                (string) data_get($toolCall, 'function.name', ''),
                $this->decodeToolArguments((string) data_get($toolCall, 'function.arguments', '{}')),
                (string) ($toolCall['id'] ?? ''),
            ))
            ->values()
            ->all();
    }

    /**
     * Decode provider-supplied JSON tool arguments into an associative array for local tool invocation.
     *
     * @return array<string, mixed>
     */
    private function decodeToolArguments(string $arguments): array
    {
        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build the assistant message payload that records the upstream provider tool-call turn.
     *
     * @return array<string, mixed>
     */
    private function assistantMessagePayload(AssistantMessage $message): array
    {
        $payload = ['role' => 'assistant'];

        if ($message->content !== '') {
            $payload['content'] = $message->content;
        }

        if ($message->toolCalls->isNotEmpty()) {
            $payload['tool_calls'] = $message->toolCalls
                ->map(fn (ToolCall $toolCall): array => $this->serializeToolCall($toolCall))
                ->all();
        }

        return $payload;
    }

    /**
     * Serialize a Laravel AI tool call into the OpenAI-compatible assistant message shape.
     *
     * @return array<string, mixed>
     */
    private function serializeToolCall(ToolCall $toolCall): array
    {
        return [
            'id' => $toolCall->resultId ?? $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments ?: (object) []),
            ],
        ];
    }

    /**
     * Convert executed tool results into OpenAI-compatible tool result messages.
     *
     * @param  array<int, ToolResult>  $toolResults
     * @return array<int, array<string, mixed>>
     */
    private function toolResultPayloads(array $toolResults): array
    {
        return array_map(fn (ToolResult $toolResult): array => [
            'role' => 'tool',
            'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
            'content' => $this->serializeToolResultOutput($toolResult->result),
        ], $toolResults);
    }

    /**
     * Serialize a tool result value into a string payload safe for upstream provider continuation.
     */
    private function serializeToolResultOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        return is_array($output) ? (string) json_encode($output) : (string) $output;
    }

    /**
     * Extract token usage metadata from an OpenAI-compatible completion response.
     *
     * @param  array<string, mixed>  $data
     */
    private function usageFromResponse(array $data): Usage
    {
        return new Usage(
            (int) data_get($data, 'usage.prompt_tokens', 0),
            (int) data_get($data, 'usage.completion_tokens', 0),
        );
    }

    /**
     * Aggregate usage across tool-call continuation steps into a single Laravel AI usage object.
     *
     * @param  Collection<int, Step>  $steps
     */
    private function combineUsage(Collection $steps): Usage
    {
        return $steps->reduce(
            fn (Usage $carry, Step $step): Usage => $carry->add($step->usage),
            new Usage(0, 0),
        );
    }

    /**
     * Map an upstream completion finish reason to a Laravel AI FinishReason enum value.
     *
     * @param  array<string, mixed>  $data
     */
    private function finishReasonFromResponse(array $data): FinishReason
    {
        return match ((string) data_get($data, 'choices.0.finish_reason', '')) {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Resolve the max-output-token request budget from Laravel AI text generation options.
     */
    private function maxOutputTokens(?TextGenerationOptions $options): int
    {
        if ($options === null) {
            return 1000;
        }

        return $options->maxTokens ?? 1000;
    }

    /**
     * Return the Laravel AI provider driver name for telemetry and stream metadata.
     */
    private function providerDriver(TextProvider $provider): string
    {
        return $provider instanceof BaseProvider ? $provider->driver() : 'laravel-ai-router';
    }

    /**
     * Return the displayable Laravel AI provider name for response and stream metadata.
     */
    private function providerName(TextProvider $provider): string
    {
        return $provider instanceof BaseProvider ? $provider->name() : $this->providerDriver($provider);
    }

    /**
     * Estimate prompt and output tokens for local routing limits when upstream usage is unavailable.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function estimateTokens(array $messages, int $maxOutputTokens): int
    {
        $chars = collect($messages)->sum(fn (array $message): int => strlen((string) ($message['content'] ?? '')));

        return max(1, (int) ceil($chars / 4)) + $maxOutputTokens;
    }

    /**
     * Extract the assistant content string from an OpenAI-compatible completion response.
     *
     * @param  array<string, mixed>  $data
     */
    private function contentFromResponse(array $data): string
    {
        $content = data_get($data, 'choices.0.message.content', '');

        if (is_array($content)) {
            return collect($content)->map(fn (mixed $segment): string => is_array($segment) ? (string) ($segment['text'] ?? '') : (string) $segment)->implode('');
        }

        return (string) $content;
    }

    /**
     * Extract a text delta from an OpenAI-compatible streaming chunk.
     *
     * @param  array<string, mixed>  $chunk
     */
    private function streamDelta(array $chunk): string
    {
        $content = data_get($chunk, 'choices.0.delta.content', '');

        if (is_array($content)) {
            return collect($content)->map(fn (mixed $segment): string => is_array($segment) ? (string) ($segment['text'] ?? '') : (string) $segment)->implode('');
        }

        return (string) $content;
    }

    /**
     * Extract a terminal finish reason from an OpenAI-compatible streaming chunk.
     *
     * @param  array<string, mixed>  $chunk
     */
    private function streamFinishReason(array $chunk): ?string
    {
        $reason = data_get($chunk, 'choices.0.finish_reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Extract token usage from an upstream streaming chunk when present.
     *
     * @param  array<string, mixed>  $chunk
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}|null
     */
    private function streamUsage(array $chunk): ?array
    {
        $usage = data_get($chunk, 'usage');

        if (! is_array($usage)) {
            return null;
        }

        $inputTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => (int) ($usage['total_tokens'] ?? $inputTokens + $outputTokens),
        ];
    }

    /**
     * Generate an event identifier for Laravel AI streaming events.
     */
    private function eventId(): string
    {
        return strtolower((string) Str::uuid7());
    }

    /**
     * Calculate elapsed request latency in milliseconds for usage analytics.
     */
    private function latencyMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Classify an exception for routing cooldown, key invalidation, failover mapping, and usage analytics.
     */
    private function errorCategory(Throwable $exception): string
    {
        if ($exception instanceof ProviderAuthenticationException) {
            return 'auth';
        }

        if ($exception instanceof ConnectionException) {
            return 'timeout';
        }

        $code = (int) $exception->getCode();

        if (in_array($code, [401, 403], true)) {
            return 'auth';
        }

        if ($code === 402) {
            return 'insufficient_credits';
        }

        if ($code === 429) {
            return 'rate_limit';
        }

        if (in_array($code, [500, 502, 503, 504, 529], true)) {
            return 'server';
        }

        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, '401'), str_contains($message, '403'), str_contains($message, 'auth'), str_contains($message, 'invalid key') => 'auth',
            str_contains($message, '402'), str_contains($message, 'payment required'), str_contains($message, 'insufficient'), str_contains($message, 'credit'), str_contains($message, 'balance'), str_contains($message, 'quota') => 'insufficient_credits',
            str_contains($message, '429'), str_contains($message, 'rate limit'), str_contains($message, 'too many') => 'rate_limit',
            str_contains($message, 'timeout'), str_contains($message, 'timed out'), str_contains($message, 'aborted'), str_contains($message, 'econn') => 'timeout',
            str_contains($message, '500'), str_contains($message, '502'), str_contains($message, '503'), str_contains($message, '504'), str_contains($message, '529'), str_contains($message, 'unavailable'), str_contains($message, 'overload'), str_contains($message, 'capacity') => 'server',
            default => 'unknown',
        };
    }

    /**
     * Determine whether an error category should put the selected route into local cooldown.
     */
    private function shouldCooldownRoute(string $category): bool
    {
        return in_array($category, ['rate_limit', 'insufficient_credits', 'timeout', 'server'], true);
    }

    /**
     * Map provider and transport failures to Laravel AI SDK exception types for failover compatibility.
     */
    private function mapExceptionForSdk(Throwable $exception, TextProvider $provider, string $category): Throwable
    {
        if ($exception instanceof RateLimitedException || $exception instanceof InsufficientCreditsException || $exception instanceof ProviderOverloadedException) {
            return $exception;
        }

        return match ($category) {
            'rate_limit' => RateLimitedException::forProvider($this->providerName($provider), (int) $exception->getCode(), $exception),
            'insufficient_credits' => InsufficientCreditsException::forProvider($this->providerName($provider), (int) $exception->getCode(), $exception),
            'timeout', 'server' => ProviderOverloadedException::forProvider($this->providerName($provider), (int) $exception->getCode(), $exception),
            default => $exception,
        };
    }
}

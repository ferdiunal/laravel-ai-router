<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Gateway;

use Closure;
use Ferdiunal\AiDevApi\Adapters\ProviderAdapterRegistry;
use Ferdiunal\AiDevApi\Routing\AiDevApiRouter;
use Ferdiunal\AiDevApi\Routing\RateLimitWindowRepository;
use Ferdiunal\AiDevApi\Routing\RouteResult;
use Ferdiunal\AiDevApi\Services\UsageLogger;
use Generator;
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
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider as BaseProvider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use LogicException;
use RuntimeException;
use Throwable;

final class AiDevApiTextGateway implements Gateway
{
    public function __construct(
        private readonly AiDevApiRouter $router,
        private readonly ProviderAdapterRegistry $adapters,
        private readonly RateLimitWindowRepository $rateLimits,
        private readonly UsageLogger $usageLogger,
    ) {}

    /**
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
            $route = $this->router->route($model, $estimatedTokens);
            $this->rateLimits->recordRequest($route->platform, $route->modelId, $route->keyId);

            $data = $this->adapters
                ->for($route->platform)
                ->complete($route->apiKey, $payloadMessages, $route->modelId, $this->mapOptions($provider, $options, $schema));

            $inputTokens = (int) data_get($data, 'usage.prompt_tokens', 0);
            $outputTokens = (int) data_get($data, 'usage.completion_tokens', 0);
            $totalTokens = (int) data_get($data, 'usage.total_tokens', $inputTokens + $outputTokens);

            $this->rateLimits->recordTokens($route->platform, $route->modelId, $route->keyId, $totalTokens);
            $this->router->recordSuccess($route);
            $this->usageLogger->success($route, $inputTokens, $outputTokens, $this->latencyMs($startedAt));

            $text = $this->contentFromResponse($data);

            return (new TextResponse(
                text: $text,
                usage: new Usage($inputTokens, $outputTokens),
                meta: new Meta($this->providerDriver($provider), $route->modelId),
            ))->withMessages(new Collection([new AssistantMessage($text)]));
        } catch (Throwable $exception) {
            $category = $this->errorCategory($exception);
            $this->usageLogger->error($route, $exception, $category, $this->latencyMs($startedAt));

            if ($route instanceof RouteResult && $category === 'rate_limit') {
                $this->rateLimits->setCooldown($route->platform, $route->modelId, $route->keyId, (int) config('ai-dev-api.routing.cooldown_seconds', 120));
                $this->router->recordRetryableFailure($route);
            }

            throw $exception;
        }
    }

    /**
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
        $startedAt = microtime(true);
        $payloadMessages = $this->mapMessages($instructions, $messages);
        $estimatedTokens = $this->estimateTokens($payloadMessages, $this->maxOutputTokens($options));
        $route = null;
        $messageId = $this->eventId();
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

            yield (new StreamStart(
                $this->eventId(),
                $this->providerDriver($provider),
                $route->modelId,
                time(),
            ))->withInvocationId($invocationId);

            foreach ($this->adapters->for($route->platform)->stream($route->apiKey, $payloadMessages, $route->modelId, $this->mapOptions($provider, $options, $schema)) as $chunk) {
                if (isset($chunk['error'])) {
                    throw new RuntimeException((string) data_get($chunk, 'error.message', 'AI Dev API streaming error.'));
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

            if ($route instanceof RouteResult && $category === 'rate_limit') {
                $this->rateLimits->setCooldown($route->platform, $route->modelId, $route->keyId, (int) config('ai-dev-api.routing.cooldown_seconds', 120));
                $this->router->recordRetryableFailure($route);
            }

            throw $exception;
        }
    }

    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        return $this;
    }

    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        throw new LogicException('AI Dev API does not support audio generation.');
    }

    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
    {
        throw new LogicException('AI Dev API does not support embeddings.');
    }

    /**
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
        throw new LogicException('AI Dev API does not support image generation.');
    }

    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        throw new LogicException('AI Dev API does not support transcription generation.');
    }

    /**
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
                throw new LogicException('AI Dev API does not support file or image attachments yet.');
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
     * @param  array<string, Type>|null  $schema
     * @return array<string, mixed>
     */
    private function mapOptions(TextProvider $provider, ?TextGenerationOptions $options, ?array $schema): array
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

        return $mapped;
    }

    private function maxOutputTokens(?TextGenerationOptions $options): int
    {
        if ($options === null) {
            return 1000;
        }

        return $options->maxTokens ?? 1000;
    }

    private function providerDriver(TextProvider $provider): string
    {
        return $provider instanceof BaseProvider ? $provider->driver() : 'ai-dev-api';
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function estimateTokens(array $messages, int $maxOutputTokens): int
    {
        $chars = collect($messages)->sum(fn (array $message): int => strlen((string) ($message['content'] ?? '')));

        return max(1, (int) ceil($chars / 4)) + $maxOutputTokens;
    }

    /** @param array<string, mixed> $data */
    private function contentFromResponse(array $data): string
    {
        $content = data_get($data, 'choices.0.message.content', '');

        if (is_array($content)) {
            return collect($content)->map(fn (mixed $segment): string => is_array($segment) ? (string) ($segment['text'] ?? '') : (string) $segment)->implode('');
        }

        return (string) $content;
    }

    /** @param array<string, mixed> $chunk */
    private function streamDelta(array $chunk): string
    {
        $content = data_get($chunk, 'choices.0.delta.content', '');

        if (is_array($content)) {
            return collect($content)->map(fn (mixed $segment): string => is_array($segment) ? (string) ($segment['text'] ?? '') : (string) $segment)->implode('');
        }

        return (string) $content;
    }

    /** @param array<string, mixed> $chunk */
    private function streamFinishReason(array $chunk): ?string
    {
        $reason = data_get($chunk, 'choices.0.finish_reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
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

    private function eventId(): string
    {
        return strtolower((string) Str::uuid7());
    }

    private function latencyMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function errorCategory(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, '429'), str_contains($message, 'rate limit'), str_contains($message, 'too many'), str_contains($message, 'quota') => 'rate_limit',
            str_contains($message, '401'), str_contains($message, '403'), str_contains($message, 'auth'), str_contains($message, 'invalid key') => 'auth',
            str_contains($message, 'timeout'), str_contains($message, 'aborted'), str_contains($message, 'econn') => 'timeout',
            str_contains($message, '500'), str_contains($message, '503'), str_contains($message, 'unavailable') => 'server',
            default => 'unknown',
        };
    }
}

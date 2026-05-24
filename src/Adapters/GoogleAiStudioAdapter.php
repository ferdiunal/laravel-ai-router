<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Adapters;

use Ferdiunal\LaravelAiRouter\Adapters\Contracts\ProviderAdapter;
use Ferdiunal\LaravelAiRouter\Exceptions\ProviderAuthenticationException;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Implements Google AI Studio / Gemini's native generateContent API behind the package ProviderAdapter contract.
 */
final class GoogleAiStudioAdapter implements ProviderAdapter
{
    private const PLATFORM = 'google';

    private const NAME = 'Google AI Studio';

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Return the provider platform slug represented by this adapter.
     */
    public function platform(): string
    {
        return self::PLATFORM;
    }

    /**
     * Return the human-readable provider name represented by this adapter.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Send a non-streaming Gemini generateContent request and normalize it to an OpenAI-compatible payload.
     */
    public function complete(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): array
    {
        $response = Http::timeout($this->timeoutSeconds($timeout))
            ->acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->post($this->endpoint($modelId, 'generateContent', $apiKey), $this->payload($messages, $options));

        $this->throwIfUnsuccessful($response, 'API error');

        return $this->normalizeCompletion((array) $response->json(), $modelId);
    }

    /**
     * Open a Gemini SSE stream and yield normalized OpenAI-compatible chunks for Laravel AI streaming.
     */
    public function stream(string $apiKey, array $messages, string $modelId, array $options = [], ?int $timeout = null): Generator
    {
        $response = Http::timeout($this->timeoutSeconds($timeout))
            ->accept('text/event-stream')
            ->withOptions(['allow_redirects' => false, 'stream' => true])
            ->post($this->endpoint($modelId, 'streamGenerateContent', $apiKey, ['alt' => 'sse']), $this->payload($messages, $options));

        $this->throwIfUnsuccessful($response, 'streaming API error');

        $id = $this->makeId();
        $sawToolCalls = false;

        foreach ($this->parseServerSentEvents($response->toPsrResponse()->getBody()) as $data) {
            $candidate = (array) data_get($data, 'candidates.0', []);
            $parts = $this->parts($candidate);
            $text = $this->extractText($parts);
            $toolCalls = $this->extractToolCalls($parts);

            if ($toolCalls !== []) {
                $sawToolCalls = true;
            }

            if ($text !== null || $toolCalls !== []) {
                yield $this->streamChunk($id, $modelId, [
                    ...($text !== null ? ['content' => $text] : []),
                    ...($toolCalls !== [] ? ['tool_calls' => $toolCalls] : []),
                ], null, $this->usagePayload($data));
            }

            $finishReason = data_get($candidate, 'finishReason');
            if (is_string($finishReason) && $finishReason !== '') {
                yield $this->streamChunk($id, $modelId, [], $sawToolCalls ? 'tool_calls' : $this->finishReason($finishReason), $this->usagePayload($data));

                return;
            }
        }

        yield $this->streamChunk($id, $modelId, [], $sawToolCalls ? 'tool_calls' : 'stop');
    }

    /**
     * Return Gemini text-generation model metadata from the model catalog endpoint.
     */
    public function models(string $apiKey): array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->get(self::API_BASE.'/models?key='.rawurlencode($apiKey));

        $this->throwIfUnsuccessful($response, 'models API error');

        return collect((array) data_get($response->json(), 'models', []))
            ->filter(fn (mixed $model): bool => is_array($model) && filled($model['name'] ?? null) && $this->supportsTextGeneration($model))
            ->map(function (array $model): array {
                return [
                    'model_id' => $this->normalizeModelId((string) $model['name']),
                    'display_name' => (string) ($model['displayName'] ?? $model['display_name'] ?? $model['name']),
                    'context_window' => isset($model['inputTokenLimit']) ? (int) $model['inputTokenLimit'] : null,
                    'supports_tools' => null,
                    'raw_metadata' => $model,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Validate Gemini API keys through the model listing endpoint.
     */
    public function validateKey(string $apiKey): bool
    {
        $response = Http::timeout(10)
            ->withOptions(['allow_redirects' => false])
            ->get(self::API_BASE.'/models?key='.rawurlencode($apiKey));

        return ! in_array($response->status(), [401, 403], true);
    }

    /**
     * Build the Gemini request payload from OpenAI-style messages/options.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function payload(array $messages, array $options): array
    {
        $mapped = $this->contents($messages);
        $generationConfig = Arr::whereNotNull([
            'temperature' => $options['temperature'] ?? null,
            'maxOutputTokens' => $options['max_tokens'] ?? null,
            'topP' => $options['top_p'] ?? null,
        ]);

        return Arr::whereNotNull([
            'contents' => $mapped['contents'],
            'systemInstruction' => $mapped['system_instruction'],
            'generationConfig' => $generationConfig === [] ? null : $generationConfig,
            'tools' => $this->tools($options['tools'] ?? []),
            'toolConfig' => $this->toolConfig($options['tool_choice'] ?? null),
        ]);
    }

    /**
     * Convert OpenAI-style messages into Gemini contents plus optional system instruction.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{contents: array<int, array<string, mixed>>, system_instruction: array<string, mixed>|null}
     */
    private function contents(array $messages): array
    {
        $systemMessages = [];
        $toolNameByCallId = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system' && filled($message['content'] ?? null)) {
                $systemMessages[] = (string) $message['content'];
            }

            foreach ((array) ($message['tool_calls'] ?? []) as $toolCall) {
                if (! is_array($toolCall)) {
                    continue;
                }

                $id = (string) ($toolCall['id'] ?? '');
                $name = (string) data_get($toolCall, 'function.name', '');
                if ($id !== '' && $name !== '') {
                    $toolNameByCallId[$id] = $name;
                }
            }
        }

        $contents = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            if ($role === 'system') {
                continue;
            }

            $entry = match ($role) {
                'assistant' => $this->assistantContent($message),
                'tool' => $this->toolContent($message, $toolNameByCallId),
                default => [
                    'role' => 'user',
                    'parts' => [['text' => (string) ($message['content'] ?? '')]],
                ],
            };

            if ($entry !== null) {
                $contents[] = $entry;
            }
        }

        return [
            'contents' => $contents,
            'system_instruction' => $systemMessages === [] ? null : ['parts' => [['text' => implode("\n\n", $systemMessages)]]],
        ];
    }

    /**
     * Convert an assistant message into Gemini model-role parts.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function assistantContent(array $message): ?array
    {
        $parts = [];
        $content = $message['content'] ?? null;

        if (is_string($content) && $content !== '') {
            $parts[] = ['text' => $content];
        }

        foreach ((array) ($message['tool_calls'] ?? []) as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $name = (string) data_get($toolCall, 'function.name', '');
            if ($name === '') {
                continue;
            }

            $functionCall = [
                'id' => (string) ($toolCall['id'] ?? ''),
                'name' => $name,
                'args' => $this->safeParseObject((string) data_get($toolCall, 'function.arguments', '{}')),
            ];

            $parts[] = Arr::whereNotNull([
                'thoughtSignature' => $toolCall['thought_signature'] ?? null,
                'functionCall' => array_filter($functionCall, static fn (mixed $value): bool => $value !== ''),
            ]);
        }

        if ($parts === []) {
            return null;
        }

        return ['role' => 'model', 'parts' => $parts];
    }

    /**
     * Convert an OpenAI tool-result message into a Gemini functionResponse part.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $toolNameByCallId
     * @return array<string, mixed>|null
     */
    private function toolContent(array $message, array $toolNameByCallId): ?array
    {
        $toolCallId = (string) ($message['tool_call_id'] ?? '');
        if ($toolCallId === '') {
            return null;
        }

        $toolName = (string) ($message['name'] ?? $toolNameByCallId[$toolCallId] ?? 'tool');

        return [
            'role' => 'user',
            'parts' => [[
                'functionResponse' => [
                    'id' => $toolCallId,
                    'name' => $toolName,
                    'response' => $this->safeParseObject((string) ($message['content'] ?? '')),
                ],
            ]],
        ];
    }

    /**
     * Convert OpenAI-compatible function tool definitions into Gemini function declarations.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function tools(mixed $tools): ?array
    {
        if (! is_array($tools) || $tools === []) {
            return null;
        }

        $declarations = collect($tools)
            ->filter(fn (mixed $tool): bool => is_array($tool) && filled(data_get($tool, 'function.name')))
            ->map(fn (array $tool): array => Arr::whereNotNull([
                'name' => (string) data_get($tool, 'function.name'),
                'description' => data_get($tool, 'function.description'),
                'parameters' => data_get($tool, 'function.parameters'),
            ]))
            ->values()
            ->all();

        return $declarations === [] ? null : [['functionDeclarations' => $declarations]];
    }

    /**
     * Convert OpenAI-compatible tool_choice into Gemini function-calling config.
     *
     * @return array<string, mixed>|null
     */
    private function toolConfig(mixed $toolChoice): ?array
    {
        if ($toolChoice === null) {
            return null;
        }

        if (is_string($toolChoice)) {
            $mode = match ($toolChoice) {
                'none' => 'NONE',
                'required' => 'ANY',
                default => 'AUTO',
            };

            return ['functionCallingConfig' => ['mode' => $mode]];
        }

        if (is_array($toolChoice) && filled(data_get($toolChoice, 'function.name'))) {
            return [
                'functionCallingConfig' => [
                    'mode' => 'ANY',
                    'allowedFunctionNames' => [(string) data_get($toolChoice, 'function.name')],
                ],
            ];
        }

        return null;
    }

    /**
     * Normalize one Gemini completion response to the OpenAI-compatible shape consumed by the gateway.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeCompletion(array $data, string $modelId): array
    {
        $candidate = (array) data_get($data, 'candidates.0', []);
        $parts = $this->parts($candidate);
        $toolCalls = $this->extractToolCalls($parts);
        $text = $this->extractText($parts);

        return [
            'id' => $this->makeId(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $modelId,
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $text,
                    ...($toolCalls !== [] ? ['tool_calls' => $toolCalls] : []),
                ],
                'finish_reason' => $toolCalls !== [] ? 'tool_calls' : $this->finishReason((string) data_get($candidate, 'finishReason', '')),
            ]],
            'usage' => $this->usagePayload($data),
            '_routed_via' => ['platform' => self::PLATFORM, 'model' => $modelId],
        ];
    }

    /**
     * Extract Gemini response parts from a candidate.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<int, array<string, mixed>>
     */
    private function parts(array $candidate): array
    {
        return collect((array) data_get($candidate, 'content.parts', []))
            ->filter(fn (mixed $part): bool => is_array($part))
            ->values()
            ->all();
    }

    /**
     * Extract text from Gemini parts.
     *
     * @param  array<int, array<string, mixed>>  $parts
     */
    private function extractText(array $parts): ?string
    {
        $text = collect($parts)
            ->map(fn (array $part): string => (string) ($part['text'] ?? ''))
            ->implode('');

        return $text === '' ? null : $text;
    }

    /**
     * Extract OpenAI-compatible tool-call payloads from Gemini functionCall parts.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, array<string, mixed>>
     */
    private function extractToolCalls(array $parts): array
    {
        $calls = [];
        $fallbackIndex = 0;

        foreach ($parts as $part) {
            $functionCall = data_get($part, 'functionCall');
            if (! is_array($functionCall) || ! filled($functionCall['name'] ?? null)) {
                continue;
            }

            $id = (string) ($functionCall['id'] ?? 'call_'.time().'_'.$fallbackIndex++);

            $calls[] = [
                'id' => $id,
                'type' => 'function',
                'function' => [
                    'name' => (string) $functionCall['name'],
                    'arguments' => $this->normalizeFunctionArguments($functionCall['args'] ?? []),
                ],
                ...(($part['thoughtSignature'] ?? null) !== null ? ['thought_signature' => $part['thoughtSignature']] : []),
            ];
        }

        return $calls;
    }

    /**
     * Build an OpenAI-compatible streaming chunk.
     *
     * @param  array<string, mixed>  $delta
     * @param  array<string, int>|null  $usage
     * @return array<string, mixed>
     */
    private function streamChunk(string $id, string $modelId, array $delta, ?string $finishReason, ?array $usage = null): array
    {
        return Arr::whereNotNull([
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $modelId,
            'choices' => [[
                'index' => 0,
                'delta' => $delta,
                'finish_reason' => $finishReason,
            ]],
            'usage' => $usage,
        ]);
    }

    /**
     * Normalize Gemini usage metadata to OpenAI-compatible token usage names.
     *
     * @param  array<string, mixed>  $data
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    private function usagePayload(array $data): array
    {
        $prompt = (int) data_get($data, 'usageMetadata.promptTokenCount', 0);
        $completion = (int) data_get($data, 'usageMetadata.candidatesTokenCount', 0);

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => (int) data_get($data, 'usageMetadata.totalTokenCount', $prompt + $completion),
        ];
    }

    /**
     * Map Gemini finish reasons to OpenAI-compatible finish reason strings.
     */
    private function finishReason(string $finishReason): string
    {
        return match (strtoupper($finishReason)) {
            'MAX_TOKENS' => 'length',
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII' => 'content_filter',
            default => 'stop',
        };
    }

    /**
     * Decode a JSON string into an object-like associative array for Gemini tool payloads.
     *
     * @return array<string, mixed>
     */
    private function safeParseObject(string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return is_array($decoded) ? $decoded : ['value' => $decoded];
        }

        return ['value' => $raw];
    }

    /**
     * Normalize a Gemini function-call args payload into an OpenAI-compatible JSON string.
     */
    private function normalizeFunctionArguments(mixed $args): string
    {
        if (is_string($args)) {
            return $args;
        }

        return (string) json_encode($args ?? (object) [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Determine if a Gemini model entry supports text generation.
     *
     * @param  array<string, mixed>  $model
     */
    private function supportsTextGeneration(array $model): bool
    {
        $methods = (array) ($model['supportedGenerationMethods'] ?? []);

        return in_array('generateContent', $methods, true) || in_array('streamGenerateContent', $methods, true);
    }

    /**
     * Strip Google's listModels `models/` prefix for routeable model IDs.
     */
    private function normalizeModelId(string $modelName): string
    {
        return str_starts_with($modelName, 'models/') ? substr($modelName, 7) : $modelName;
    }

    /**
     * Build a Google Gemini API endpoint URL with query parameters.
     *
     * @param  array<string, string>  $query
     */
    private function endpoint(string $modelId, string $method, string $apiKey, array $query = []): string
    {
        $query = ['key' => $apiKey, ...$query];

        return self::API_BASE.'/models/'.$this->normalizeModelId($modelId).':'.$method.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Resolve the HTTP timeout in seconds from the per-request override or adapter default.
     */
    private function timeoutSeconds(?int $timeout = null): float
    {
        return max(1, $timeout ?? 15);
    }

    /**
     * Parse Google SSE frames into decoded Gemini response payloads with bounded buffers.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function parseServerSentEvents(StreamInterface $streamBody): Generator
    {
        $dataLines = [];
        $eventBytes = 0;
        $maxLineBytes = max(1024, (int) config('laravel-ai-router.streaming.max_line_bytes', 65_536));
        $maxEventBytes = max(1024, (int) config('laravel-ai-router.streaming.max_event_bytes', 1_048_576));

        while (! $streamBody->eof()) {
            $line = rtrim($this->readLine($streamBody, $maxLineBytes), "\r\n");

            if ($line === '') {
                $event = $this->decodeServerSentEvent($dataLines);
                $dataLines = [];
                $eventBytes = 0;

                if ($event === '__done__') {
                    return;
                }

                if (is_array($event)) {
                    yield $event;
                }

                continue;
            }

            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $dataLine = ltrim(substr($line, 5), ' ');
            $eventBytes += strlen($dataLine) + 1;

            if ($eventBytes > $maxEventBytes) {
                throw new RuntimeException("SSE event exceeded the configured {$maxEventBytes} byte limit.");
            }

            $dataLines[] = $dataLine;
        }

        $event = $this->decodeServerSentEvent($dataLines);
        if (is_array($event)) {
            yield $event;
        }
    }

    /**
     * Decode accumulated SSE data lines into a Gemini response payload, done sentinel, or ignored malformed event.
     *
     * @param  array<int, string>  $dataLines
     * @return array<string, mixed>|'__done__'|null
     */
    private function decodeServerSentEvent(array $dataLines): array|string|null
    {
        if ($dataLines === []) {
            return null;
        }

        $payload = trim(implode("\n", $dataLines));

        if ($payload === '[DONE]') {
            return '__done__';
        }

        $decoded = json_decode($payload, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    /**
     * Read one SSE line while enforcing the configured line byte limit.
     */
    private function readLine(StreamInterface $streamBody, int $maxLineBytes): string
    {
        $buffer = '';

        while (! $streamBody->eof()) {
            $byte = $streamBody->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if (strlen($buffer) > $maxLineBytes) {
                throw new RuntimeException("SSE line exceeded the configured {$maxLineBytes} byte limit.");
            }

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    /**
     * Convert unsuccessful upstream HTTP responses into routed provider exceptions.
     */
    private function throwIfUnsuccessful(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) data_get($response->json(), 'error.message', $response->reason());

        if (in_array($response->status(), [401, 403], true)) {
            throw new ProviderAuthenticationException(self::NAME, $response->status(), $message);
        }

        throw new RuntimeException(self::NAME.' '.$context.' '.$response->status().': '.$message, $response->status());
    }

    /**
     * Return a stable-enough OpenAI-compatible completion id prefix for normalized responses.
     */
    private function makeId(): string
    {
        return 'chatcmpl-'.Str::uuid()->toString();
    }
}

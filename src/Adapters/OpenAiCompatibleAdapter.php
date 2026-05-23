<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Adapters;

use Ferdiunal\AiDevApi\Adapters\Contracts\ProviderAdapter;
use Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class OpenAiCompatibleAdapter implements ProviderAdapter
{
    /** @param array<string, string> $extraHeaders */
    public function __construct(
        private readonly string $platform,
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly array $extraHeaders = [],
        private readonly ?string $validateUrl = null,
        private readonly int $timeoutMs = 15_000,
    ) {}

    public function platform(): string
    {
        return $this->platform;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function complete(string $apiKey, array $messages, string $modelId, array $options = []): array
    {
        $response = Http::timeout($this->timeoutSeconds())
            ->withToken($apiKey)
            ->withHeaders($this->extraHeaders)
            ->acceptJson()
            ->post($this->endpoint('chat/completions'), $this->payload($messages, $modelId, $options));

        if (! $response->successful()) {
            $message = data_get($response->json(), 'error.message', $response->reason());

            throw new RuntimeException("{$this->name} API error {$response->status()}: {$message}");
        }

        $data = $response->json();
        $this->normalizeChoices($data);
        $data['_routed_via'] = ['platform' => $this->platform, 'model' => $modelId];

        return $data;
    }

    public function stream(string $apiKey, array $messages, string $modelId, array $options = []): Generator
    {
        $response = Http::timeout($this->timeoutSeconds())
            ->withToken($apiKey)
            ->withHeaders($this->extraHeaders)
            ->accept('text/event-stream')
            ->withOptions(['stream' => true])
            ->post($this->endpoint('chat/completions'), $this->streamPayload($messages, $modelId, $options));

        if (! $response->successful()) {
            $message = data_get($response->json(), 'error.message', $response->reason());

            throw new RuntimeException("{$this->name} streaming API error {$response->status()}: {$message}");
        }

        yield from $this->parseServerSentEvents($response->toPsrResponse()->getBody());
    }

    public function models(string $apiKey): array
    {
        $response = Http::timeout($this->timeoutSeconds())
            ->withToken($apiKey)
            ->withHeaders($this->extraHeaders)
            ->acceptJson()
            ->get($this->endpoint('models'));

        if (! $response->successful()) {
            $message = data_get($response->json(), 'error.message', $response->reason());

            throw new RuntimeException("{$this->name} models API error {$response->status()}: {$message}");
        }

        $data = $response->json();

        return collect((array) data_get($data, 'data', []))
            ->filter(fn (mixed $model): bool => is_array($model) && filled($model['id'] ?? null))
            ->map(fn (array $model): array => [
                'model_id' => (string) $model['id'],
                'display_name' => (string) ($model['name'] ?? $model['id']),
                'context_window' => isset($model['context_length']) ? (int) $model['context_length'] : null,
                'supports_tools' => data_get($model, 'supported_parameters') ? in_array('tools', (array) data_get($model, 'supported_parameters'), true) : null,
                'raw_metadata' => $model,
            ])
            ->values()
            ->all();
    }

    public function validateKey(string $apiKey): bool
    {
        $response = Http::timeout(10)
            ->withToken($apiKey)
            ->withHeaders($this->extraHeaders)
            ->get($this->validateUrl ?? $this->endpoint('models'));

        return ! in_array($response->status(), [401, 403], true);
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function timeoutSeconds(): float
    {
        return max(1, $this->timeoutMs / 1000);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function payload(array $messages, string $modelId, array $options): array
    {
        return Arr::whereNotNull([
            'model' => $modelId,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
            'top_p' => $options['top_p'] ?? null,
            'tools' => $options['tools'] ?? null,
            'tool_choice' => $options['tool_choice'] ?? null,
            'parallel_tool_calls' => $options['parallel_tool_calls'] ?? null,
            'response_format' => $options['response_format'] ?? null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function streamPayload(array $messages, string $modelId, array $options): array
    {
        return [
            ...$this->payload($messages, $modelId, $options),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];
    }

    /** @return Generator<int, array<string, mixed>> */
    private function parseServerSentEvents(StreamInterface $streamBody): Generator
    {
        while (! $streamBody->eof()) {
            $line = trim($this->readLine($streamBody));

            if ($line === '' || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, 5));

            if ($payload === '[DONE]') {
                return;
            }

            $decoded = json_decode($payload, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                yield $decoded;
            }
        }
    }

    private function readLine(StreamInterface $streamBody): string
    {
        $buffer = '';

        while (! $streamBody->eof()) {
            $byte = $streamBody->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalizeChoices(array &$data): void
    {
        if (! isset($data['choices']) || ! is_array($data['choices'])) {
            return;
        }

        foreach ($data['choices'] as &$choice) {
            if (! isset($choice['message']) || ! is_array($choice['message'])) {
                continue;
            }

            $message = &$choice['message'];

            if (isset($message['content']) && is_array($message['content'])) {
                $message['content'] = collect($message['content'])
                    ->map(fn (mixed $segment): string => is_array($segment) ? (string) ($segment['text'] ?? '') : (string) $segment)
                    ->implode('');
            }

            $hasToolCalls = isset($message['tool_calls']) && is_array($message['tool_calls']) && count($message['tool_calls']) > 0;

            if (! $hasToolCalls && (($message['content'] ?? null) === '' || ($message['content'] ?? null) === null)) {
                $foldedReasoning = $message['reasoning_content'] ?? $message['reasoning'] ?? null;

                if (is_string($foldedReasoning) && $foldedReasoning !== '') {
                    $message['content'] = $foldedReasoning;
                }
            }
        }
        unset($choice, $message);
    }
}

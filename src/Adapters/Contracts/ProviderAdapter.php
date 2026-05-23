<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Adapters\Contracts;

use Generator;

interface ProviderAdapter
{
    public function platform(): string;

    public function name(): string;

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function complete(string $apiKey, array $messages, string $modelId, array $options = []): array;

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(string $apiKey, array $messages, string $modelId, array $options = []): Generator;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function models(string $apiKey): array;

    public function validateKey(string $apiKey): bool;
}

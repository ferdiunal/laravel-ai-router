<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Support;

use Laravel\Ai\AiManager;

/**
 * Small convenience wrapper returned by the package's global ai() helper.
 */
final class AiHelper
{
    public function __construct(private readonly AiManager $manager) {}

    /**
     * Return the underlying Laravel AI manager for advanced SDK access.
     */
    public function manager(): AiManager
    {
        return $this->manager;
    }

    /**
     * Start a lightweight text prompt against a provider/model pair.
     */
    public function using(string $provider, ?string $model = null): PendingTextPrompt
    {
        return new PendingTextPrompt($provider, $model);
    }

    /**
     * Proxy unknown calls to the underlying Laravel AI manager.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->manager->{$method}(...$parameters);
    }
}

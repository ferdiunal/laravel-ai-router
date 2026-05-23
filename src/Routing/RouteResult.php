<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Routing;

/**
 * Carries the resolved provider, model, database row, credential, and label selected for a routed request.
 */
final readonly class RouteResult
{
    /**
     * Create an immutable route result carrying the selected provider, model, key, and label metadata.
     */
    public function __construct(
        public string $platform,
        public string $modelId,
        public int $modelDbId,
        public string $displayName,
        public int $keyId,
        public string $apiKey,
        public string $providerLabel,
    ) {}
}

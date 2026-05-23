<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Routing;

final readonly class RouteResult
{
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

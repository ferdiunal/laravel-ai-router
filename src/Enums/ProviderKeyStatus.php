<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Enums;

enum ProviderKeyStatus: string
{
    case Healthy = 'healthy';
    case RateLimited = 'rate_limited';
    case Invalid = 'invalid';
    case Error = 'error';
    case Unknown = 'unknown';
}

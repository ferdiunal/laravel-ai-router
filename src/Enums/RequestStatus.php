<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Enums;

enum RequestStatus: string
{
    case Success = 'success';
    case Error = 'error';
    case Retry = 'retry';
    case Skipped = 'skipped';
}

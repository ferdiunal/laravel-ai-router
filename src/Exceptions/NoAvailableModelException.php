<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Exceptions;

use RuntimeException;

/**
 * Represents a routing failure caused by the absence of healthy provider keys or eligible cached models.
 */
final class NoAvailableModelException extends RuntimeException {}

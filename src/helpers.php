<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Support\AiHelper;
use Laravel\Ai\AiManager;

if (! function_exists('ai')) {
    /**
     * Resolve Laravel AI Router's convenience AI helper from the container.
     */
    function ai(): AiHelper
    {
        return new AiHelper(app(AiManager::class));
    }
}

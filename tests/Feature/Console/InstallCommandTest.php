<?php

declare(strict_types=1);
use Ferdiunal\AiDevApi\Tests\TestCase;

it('runs the prompt-driven install command without option flags', function () {
    /** @var TestCase $this */
    $this->artisan('ai-dev-api:install')
        ->expectsOutputToContain('AI Dev API install flow completed.')
        ->assertSuccessful();
});

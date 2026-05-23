<?php

declare(strict_types=1);

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Services\ProviderKeyManager;
use Illuminate\Validation\ValidationException;

function migrateLaravelAiRouterForProviderKeyTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('requires provider labels to be unique per provider', function () {
    migrateLaravelAiRouterForProviderKeyTests();

    LaravelAiRouterProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => 'key-openrouter-value-123456',
        'status' => 'unknown',
        'enabled' => true,
    ]);

    app(ProviderKeyManager::class)->add('openrouter', 'key-openrouter-value-abcdef', 'Primary', refreshModels: false);
})->throws(ValidationException::class);

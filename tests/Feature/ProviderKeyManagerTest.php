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

it('rejects blank api keys for providers that require credentials', function (string $platform) {
    migrateLaravelAiRouterForProviderKeyTests();

    app(ProviderKeyManager::class)->add($platform, '   ', 'Primary', refreshModels: false);
})->with(['openrouter', 'groq'])->throws(ValidationException::class);

it('normalizes blank api keys to the anonymous placeholder for providers that allow placeholders', function (string $platform) {
    migrateLaravelAiRouterForProviderKeyTests();

    $key = app(ProviderKeyManager::class)->add($platform, '   ', 'Anonymous', refreshModels: false);

    expect($key->key)->toBe('anonymous-placeholder')
        ->and($key->platform)->toBe($platform)
        ->and($key->label)->toBe('Anonymous');
})->with(['pollinations', 'llm7', 'kilo']);

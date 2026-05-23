<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;
use Ferdiunal\AiDevApi\Services\ProviderKeyManager;
use Illuminate\Validation\ValidationException;

function migrateAiDevApiForProviderKeyTests(): void
{
    foreach (glob(__DIR__.'/../../database/migrations/*.php.stub') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }
}

it('requires provider labels to be unique per provider', function () {
    migrateAiDevApiForProviderKeyTests();

    AiDevApiProviderKey::query()->create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => 'key-openrouter-value-123456',
        'status' => 'unknown',
        'enabled' => true,
    ]);

    app(ProviderKeyManager::class)->add('openrouter', 'key-openrouter-value-abcdef', 'Primary', refreshModels: false);
})->throws(ValidationException::class);

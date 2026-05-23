<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Models\AiDevApiProviderKey;

it('encrypts provider keys and exposes only masked values', function () {
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migrationFile) {
        $migration = include $migrationFile;
        $migration->up();
    }

    $plainKey = 'key-openrouter-value-123456';

    $key = AiDevApiProviderKey::create([
        'platform' => 'openrouter',
        'label' => 'Primary',
        'key' => $plainKey,
        'status' => 'unknown',
        'enabled' => true,
    ]);

    $raw = $key->getRawOriginal('encrypted_key');

    expect($raw)->not->toContain($plainKey)
        ->and($key->key)->toBe($plainKey)
        ->and($key->masked_key)->toBe('key-...3456')
        ->and($key->toArray())->not->toHaveKey('key')
        ->and(json_encode($key->toArray()))->not->toContain($plainKey);
});

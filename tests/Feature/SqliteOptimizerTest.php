<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Services\SqliteOptimizer;
use Illuminate\Support\Facades\DB;

it('applies sqlite pragmas only to sqlite connections and skips WAL for memory databases', function () {
    config()->set('database.connections.ai_dev_memory', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('ai-dev-api.database.sqlite.optimize', true);

    DB::purge('ai_dev_memory');

    $applied = app(SqliteOptimizer::class)->optimize('ai_dev_memory');

    expect($applied)->toContain('PRAGMA foreign_keys = ON')
        ->and(collect($applied)->contains(fn (string $statement): bool => str_contains($statement, 'journal_mode')))->toBeFalse();
});

<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Services\SqliteOptimizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

it('sanitizes sqlite pragma config values before applying them', function () {
    $database = sys_get_temp_dir().'/ai-dev-api-sqlite-optimizer-'.Str::uuid().'.sqlite';
    touch($database);

    config()->set('database.connections.ai_dev_file', [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
    ]);
    config()->set('ai-dev-api.database.sqlite.optimize', true);
    config()->set('ai-dev-api.database.sqlite.journal_mode', 'WAL; DROP TABLE users');
    config()->set('ai-dev-api.database.sqlite.synchronous', 'FULL; DROP TABLE users');
    config()->set('ai-dev-api.database.sqlite.busy_timeout_ms', -25);
    config()->set('ai-dev-api.database.sqlite.cache_size_kb', -10);

    DB::purge('ai_dev_file');

    try {
        $applied = app(SqliteOptimizer::class)->optimize('ai_dev_file');
    } finally {
        DB::purge('ai_dev_file');
        @unlink($database);
    }

    expect($applied)->toContain('PRAGMA journal_mode = WAL')
        ->and($applied)->toContain('PRAGMA synchronous = NORMAL')
        ->and($applied)->toContain('PRAGMA busy_timeout = 0')
        ->and(collect($applied)->contains(fn (string $statement): bool => str_contains($statement, 'DROP TABLE')))->toBeFalse()
        ->and(collect($applied)->contains(fn (string $statement): bool => str_contains($statement, 'cache_size')))->toBeFalse();
});

it('clamps oversized sqlite pragma config values before applying them', function () {
    $database = sys_get_temp_dir().'/ai-dev-api-sqlite-optimizer-'.Str::uuid().'.sqlite';
    touch($database);

    config()->set('database.connections.ai_dev_file', [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
    ]);
    config()->set('ai-dev-api.database.sqlite.optimize', true);
    config()->set('ai-dev-api.database.sqlite.busy_timeout_ms', 999_999_999);
    config()->set('ai-dev-api.database.sqlite.cache_size_kb', 999_999_999);

    DB::purge('ai_dev_file');

    try {
        $applied = app(SqliteOptimizer::class)->optimize('ai_dev_file');
    } finally {
        DB::purge('ai_dev_file');
        @unlink($database);
    }

    expect($applied)->toContain('PRAGMA busy_timeout = 10000')
        ->and($applied)->toContain('PRAGMA cache_size = -1000000');
});

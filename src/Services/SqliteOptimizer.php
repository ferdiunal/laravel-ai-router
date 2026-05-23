<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SqliteOptimizer
{
    private const DEFAULT_BUSY_TIMEOUT_MS = 5000;

    private const MAX_BUSY_TIMEOUT_MS = 10000;

    private const DEFAULT_CACHE_SIZE_KB = 20000;

    private const MAX_CACHE_SIZE_KB = 1000000;

    /** @var array<int, string> */
    private const JOURNAL_MODES = ['DELETE', 'TRUNCATE', 'PERSIST', 'MEMORY', 'WAL', 'OFF'];

    /** @var array<int, string> */
    private const SYNCHRONOUS_MODES = ['OFF', 'NORMAL', 'FULL', 'EXTRA'];

    /** @return array<int, string> applied pragma statements */
    public function optimize(?string $connection = null): array
    {
        if (! (bool) config('ai-dev-api.database.sqlite.optimize', true)) {
            return [];
        }

        $connectionName = $connection ?: config('ai-dev-api.database.connection') ?: config('database.default');
        $db = DB::connection($connectionName);

        if ($db->getDriverName() !== 'sqlite') {
            return [];
        }

        $database = (string) config("database.connections.{$connectionName}.database", '');
        $statements = [
            'PRAGMA foreign_keys = ON',
            'PRAGMA busy_timeout = '.$this->clampInt(config('ai-dev-api.database.sqlite.busy_timeout_ms', self::DEFAULT_BUSY_TIMEOUT_MS), 0, self::MAX_BUSY_TIMEOUT_MS),
            'PRAGMA synchronous = '.$this->enumValue(config('ai-dev-api.database.sqlite.synchronous', 'NORMAL'), self::SYNCHRONOUS_MODES, 'NORMAL'),
            'PRAGMA temp_store = MEMORY',
        ];

        $cacheSize = $this->clampInt(config('ai-dev-api.database.sqlite.cache_size_kb', self::DEFAULT_CACHE_SIZE_KB), 0, self::MAX_CACHE_SIZE_KB);
        if ($cacheSize > 0) {
            $statements[] = 'PRAGMA cache_size = -'.$cacheSize;
        }

        if ($database !== ':memory:') {
            array_unshift($statements, 'PRAGMA journal_mode = '.$this->enumValue(config('ai-dev-api.database.sqlite.journal_mode', 'WAL'), self::JOURNAL_MODES, 'WAL'));
        }

        return $this->apply($db, $statements);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function enumValue(mixed $value, array $allowed, string $default): string
    {
        $candidate = strtoupper(trim((string) $value));

        return in_array($candidate, $allowed, true) ? $candidate : $default;
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        return min($max, max($min, (int) $value));
    }

    /**
     * @param  array<int, string>  $statements
     * @return array<int, string>
     */
    private function apply(ConnectionInterface $db, array $statements): array
    {
        $applied = [];

        foreach ($statements as $statement) {
            try {
                $db->statement($statement);
                $applied[] = $statement;
            } catch (Throwable) {
                // Host app sqlite builds may reject a pragma; never fail boot/install.
            }
        }

        return $applied;
    }
}

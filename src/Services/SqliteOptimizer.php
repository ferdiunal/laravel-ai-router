<?php

declare(strict_types=1);

namespace Ferdiunal\AiDevApi\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SqliteOptimizer
{
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
        $statements = ['PRAGMA foreign_keys = ON', 'PRAGMA busy_timeout = '.(int) config('ai-dev-api.database.sqlite.busy_timeout_ms', 5000), 'PRAGMA synchronous = '.config('ai-dev-api.database.sqlite.synchronous', 'NORMAL'), 'PRAGMA temp_store = MEMORY'];

        $cacheSize = (int) config('ai-dev-api.database.sqlite.cache_size_kb', 20000);
        if ($cacheSize > 0) {
            $statements[] = 'PRAGMA cache_size = -'.$cacheSize;
        }

        if ($database !== ':memory:') {
            array_unshift($statements, 'PRAGMA journal_mode = '.config('ai-dev-api.database.sqlite.journal_mode', 'WAL'));
        }

        return $this->apply($db, $statements);
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

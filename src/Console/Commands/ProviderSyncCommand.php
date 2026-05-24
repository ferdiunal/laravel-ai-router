<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Console\Commands;

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterProviderKey;
use Ferdiunal\LaravelAiRouter\Services\ProviderSyncResult;
use Ferdiunal\LaravelAiRouter\Services\ProviderSyncService;
use Illuminate\Console\Command;

/**
 * Validates provider keys, refreshes model caches, and reports local quota readiness.
 */
final class ProviderSyncCommand extends Command
{
    protected $signature = 'laravel-ai-router:provider:sync
        {--all : Sync every stored provider key}
        {--provider= : Sync all keys for one provider platform}
        {--key-id= : Sync one provider key by package storage ID}
        {--no-refresh-models : Validate credentials and quota without refreshing provider model caches}
        {--dry-run : Validate and inspect without persisting key/cache status changes}
        {--fail-on-invalid : Return a failing exit code when any credential is invalid}
        {--json : Emit stable machine-readable JSON output}';

    protected $description = 'Validate provider credentials, refresh cached models, and show local quota readiness.';

    /**
     * Resolve the requested provider key target, sync it, and render table or JSON output.
     */
    public function handle(ProviderSyncService $sync): int
    {
        $keys = $this->targetKeys();
        if ($keys === null) {
            return self::FAILURE;
        }

        if ($keys === []) {
            $this->error('No provider keys matched the requested sync target.');

            return self::FAILURE;
        }

        $refreshModels = ! (bool) $this->option('no-refresh-models');
        $dryRun = (bool) $this->option('dry-run');

        $results = collect($keys)
            ->map(fn (LaravelAiRouterProviderKey $key): ProviderSyncResult => $sync->syncKey($key, $refreshModels, $dryRun))
            ->values()
            ->all();

        if ((bool) $this->option('json')) {
            $this->line($this->jsonPayload($results));
        } else {
            $this->table(
                ['ID', 'Provider', 'Label', 'API Status', 'Cached/Auto', 'Model', 'Blocked', 'RPM', 'RPD', 'TPM', 'TPD', 'Cooldown', 'Message'],
                collect($results)->flatMap(fn (ProviderSyncResult $result): array => $result->tableRows())->all(),
            );
        }

        if (collect($results)->contains(fn (ProviderSyncResult $result): bool => $result->apiStatus === 'error')) {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-invalid')
            && collect($results)->contains(fn (ProviderSyncResult $result): bool => $result->apiStatus === 'invalid')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the command's explicit sync target to provider key rows.
     *
     * @return array<int, LaravelAiRouterProviderKey>|null
     */
    private function targetKeys(): ?array
    {
        $targets = array_filter([
            (bool) $this->option('all') ? 'all' : null,
            filled($this->option('provider')) ? 'provider' : null,
            filled($this->option('key-id')) ? 'key-id' : null,
        ]);

        if ($targets === []) {
            $this->error('Use --all, --provider=, or --key-id= to choose which provider keys to sync.');

            return null;
        }

        if (count($targets) > 1) {
            $this->error('Use only one sync target: --all, --provider=, or --key-id=.');

            return null;
        }

        if ((bool) $this->option('all')) {
            return LaravelAiRouterProviderKey::query()
                ->orderBy('platform')
                ->orderBy('label')
                ->get()
                ->all();
        }

        if (filled($this->option('provider'))) {
            return LaravelAiRouterProviderKey::query()
                ->where('platform', (string) $this->option('provider'))
                ->orderBy('label')
                ->get()
                ->all();
        }

        $keyId = (int) $this->option('key-id');
        if ($keyId <= 0) {
            $this->error('--key-id must be a positive integer.');

            return null;
        }

        $key = LaravelAiRouterProviderKey::query()->find($keyId);
        if (! $key instanceof LaravelAiRouterProviderKey) {
            return [];
        }

        return [$key];
    }

    /**
     * Encode sync results as stable, pretty JSON for automation.
     *
     * @param  array<int, ProviderSyncResult>  $results
     */
    private function jsonPayload(array $results): string
    {
        $json = json_encode([
            'results' => collect($results)->map(fn (ProviderSyncResult $result): array => $result->toArray())->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{"results":[]}' : $json;
    }
}

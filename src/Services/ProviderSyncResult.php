<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

/**
 * Secret-free provider sync payload shared by table and JSON command output.
 */
final class ProviderSyncResult
{
    /**
     * @param  array<string, mixed>  $quota
     */
    public function __construct(
        public readonly int $keyId,
        public readonly string $platform,
        public readonly string $label,
        public readonly bool $enabled,
        public readonly string $apiStatus,
        public readonly bool $modelsRefreshed,
        public readonly int $cachedModelCount,
        public readonly int $selectedAutoModelCount,
        public readonly array $quota,
        public readonly string $checkedAt,
        public readonly string $message,
    ) {}

    /**
     * Return a stable, secret-free JSON payload shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key_id' => $this->keyId,
            'provider' => $this->platform,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'api_status' => $this->apiStatus,
            'models_refreshed' => $this->modelsRefreshed,
            'cached_model_count' => $this->cachedModelCount,
            'selected_auto_model_count' => $this->selectedAutoModelCount,
            'quota' => $this->quota,
            'checked_at' => $this->checkedAt,
            'message' => $this->message,
        ];
    }

    /**
     * Return model-level table rows used by the interactive command output.
     *
     * @return array<int, array<int, string>>
     */
    public function tableRows(): array
    {
        $models = data_get($this->quota, 'models', []);

        if (! is_array($models) || $models === []) {
            return [$this->tableRowForModel(null)];
        }

        return collect($models)
            ->map(fn (mixed $model): array => $this->tableRowForModel(is_array($model) ? $model : null))
            ->all();
    }

    /**
     * Return one model-level table row, or a placeholder row when no selected model exists.
     *
     * @param  array<string, mixed>|null  $model
     * @return array<int, string>
     */
    private function tableRowForModel(?array $model): array
    {
        return [
            (string) $this->keyId,
            $this->platform,
            $this->label,
            $this->apiStatus,
            $this->modelCountSummary(),
            $this->modelId($model),
            $this->blockedLabel($model),
            $this->limitSummary($model, 'rpm'),
            $this->limitSummary($model, 'rpd'),
            $this->limitSummary($model, 'tpm'),
            $this->limitSummary($model, 'tpd'),
            $this->cooldownLabel($model),
            $this->message,
        ];
    }

    /**
     * Return cached/auto counts in a compact table cell.
     */
    private function modelCountSummary(): string
    {
        return sprintf('%d/%d', $this->cachedModelCount, $this->selectedAutoModelCount);
    }

    /**
     * Return the model ID for a model quota row.
     *
     * @param  array<string, mixed>|null  $model
     */
    private function modelId(?array $model): string
    {
        $modelId = data_get($model, 'model_id');

        return is_string($modelId) && $modelId !== '' ? $modelId : '—';
    }

    /**
     * Return whether one model quota row is currently blocked locally.
     *
     * @param  array<string, mixed>|null  $model
     */
    private function blockedLabel(?array $model): string
    {
        if ($model === null) {
            return 'n/a';
        }

        return (bool) data_get($model, 'blocked', false) ? 'yes' : 'no';
    }

    /**
     * Return a compact remaining/limit cell for one rate or token window.
     *
     * @param  array<string, mixed>|null  $model
     */
    private function limitSummary(?array $model, string $type): string
    {
        if ($model === null) {
            return 'n/a';
        }

        $limit = $this->integerValue(data_get($model, "limits.{$type}.limit"));
        if ($limit === null || $limit <= 0) {
            return 'unlimited';
        }

        $remaining = $this->integerValue(data_get($model, "limits.{$type}.remaining"));
        $used = $this->integerValue(data_get($model, "limits.{$type}.used"));

        return sprintf('%d/%d (%d used)', $remaining ?? $limit, $limit, $used ?? 0);
    }

    /**
     * Return active cooldown timestamp for one model quota row.
     *
     * @param  array<string, mixed>|null  $model
     */
    private function cooldownLabel(?array $model): string
    {
        $cooldown = data_get($model, 'cooldown_until');

        return is_string($cooldown) && $cooldown !== '' ? $cooldown : '—';
    }

    /**
     * Normalize numeric quota cells that may arrive from arrays or decoded JSON.
     */
    private function integerValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

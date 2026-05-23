<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Services;

use Ferdiunal\LaravelAiRouter\Models\LaravelAiRouterSetting;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Reads and writes the package-level default text model preference stored in internal package storage.
 */
final class ModelPreferenceManager
{
    public const DEFAULT_TEXT_MODEL_KEY = 'default_text_model_id';

    /**
     * Return the configured default text model after applying the persisted package preference fallback.
     */
    public function defaultTextModel(string $fallback = 'auto'): string
    {
        try {
            $setting = LaravelAiRouterSetting::query()->find(self::DEFAULT_TEXT_MODEL_KEY);
        } catch (QueryException) {
            return $fallback;
        } catch (Throwable) {
            return $fallback;
        }

        $modelId = data_get($setting?->value, 'model_id');

        return is_string($modelId) && trim($modelId) !== '' ? $modelId : $fallback;
    }

    /**
     * Persist the selected default text model in package settings without mutating configuration files.
     */
    public function setDefaultTextModel(string $modelId): void
    {
        $modelId = trim($modelId) !== '' ? trim($modelId) : 'auto';

        LaravelAiRouterSetting::query()->updateOrCreate(
            ['key' => self::DEFAULT_TEXT_MODEL_KEY],
            ['value' => ['model_id' => $modelId]],
        );
    }

    /**
     * Remove the persisted default text model preference from package settings.
     */
    public function clearDefaultTextModel(): void
    {
        LaravelAiRouterSetting::query()->whereKey(self::DEFAULT_TEXT_MODEL_KEY)->delete();
    }
}

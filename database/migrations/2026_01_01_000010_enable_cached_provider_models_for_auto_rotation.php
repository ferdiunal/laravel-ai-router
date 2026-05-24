<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('laravel-ai-router.database.connection') ?: 'laravel-ai-router';
        $schema = Schema::connection($connection);

        foreach (['laravel_ai_router_models', 'laravel_ai_router_fallbacks', 'laravel_ai_router_provider_keys', 'laravel_ai_router_provider_model_caches'] as $table) {
            if (! $schema->hasTable($table)) {
                return;
            }
        }

        $database = DB::connection($connection);
        $now = now();
        $nextPriority = ((int) $database->table('laravel_ai_router_fallbacks')->max('priority')) + 1;

        $modelIds = $database->table('laravel_ai_router_provider_model_caches as cache')
            ->join('laravel_ai_router_provider_keys as keys', 'keys.id', '=', 'cache.provider_key_id')
            ->join('laravel_ai_router_models as models', function ($join): void {
                $join->on('models.platform', '=', 'cache.platform')
                    ->on('models.model_id', '=', 'cache.model_id');
            })
            ->where('cache.enabled', true)
            ->where('models.enabled', true)
            ->where('keys.enabled', true)
            ->where('keys.status', '!=', 'invalid')
            ->where(function ($query) use ($now): void {
                $query->whereNull('keys.models_cache_expires_at')
                    ->orWhere('keys.models_cache_expires_at', '>=', $now);
            })
            ->distinct()
            ->orderBy('models.id')
            ->pluck('models.id');

        foreach ($modelIds as $modelId) {
            $fallback = $database->table('laravel_ai_router_fallbacks')
                ->where('laravel_ai_router_model_id', $modelId)
                ->first();

            if ($fallback !== null) {
                if (! (bool) $fallback->enabled) {
                    $database->table('laravel_ai_router_fallbacks')
                        ->where('id', $fallback->id)
                        ->update([
                            'enabled' => true,
                            'updated_at' => $now,
                        ]);
                }

                continue;
            }

            $database->table('laravel_ai_router_fallbacks')->insert([
                'laravel_ai_router_model_id' => $modelId,
                'priority' => $nextPriority++,
                'enabled' => true,
                'penalty' => 0,
                'penalty_updated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data backfill only. Do not disable fallbacks on rollback because users may
        // have made explicit routing choices after this migration ran.
    }
};

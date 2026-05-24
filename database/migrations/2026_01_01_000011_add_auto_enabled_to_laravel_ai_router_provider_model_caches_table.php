<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('laravel-ai-router.database.connection') ?: 'laravel-ai-router';
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('laravel_ai_router_provider_model_caches')) {
            return;
        }

        if ($schema->hasColumn('laravel_ai_router_provider_model_caches', 'auto_enabled')) {
            return;
        }

        $schema->table('laravel_ai_router_provider_model_caches', function (Blueprint $table): void {
            $table->boolean('auto_enabled')->default(false)->after('enabled');
            $table->index(['provider_key_id', 'auto_enabled', 'enabled'], 'laravel_ai_router_model_cache_key_auto_enabled_idx');
            $table->index(['platform', 'provider_label', 'auto_enabled', 'enabled'], 'laravel_ai_router_model_cache_provider_auto_enabled_idx');
        });

        foreach (['laravel_ai_router_models', 'laravel_ai_router_fallbacks', 'laravel_ai_router_provider_keys'] as $table) {
            if (! $schema->hasTable($table)) {
                return;
            }
        }

        $database = DB::connection($connection);
        $now = now();

        $cacheIds = $database->table('laravel_ai_router_provider_model_caches as cache')
            ->join('laravel_ai_router_provider_keys as keys', 'keys.id', '=', 'cache.provider_key_id')
            ->join('laravel_ai_router_models as models', function ($join): void {
                $join->on('models.platform', '=', 'cache.platform')
                    ->on('models.model_id', '=', 'cache.model_id');
            })
            ->join('laravel_ai_router_fallbacks as fallbacks', 'fallbacks.laravel_ai_router_model_id', '=', 'models.id')
            ->where('cache.enabled', true)
            ->where('models.enabled', true)
            ->where('fallbacks.enabled', true)
            ->where('keys.enabled', true)
            ->where('keys.status', '!=', 'invalid')
            ->where(function ($query) use ($now): void {
                $query->whereNull('keys.models_cache_expires_at')
                    ->orWhere('keys.models_cache_expires_at', '>=', $now);
            })
            ->distinct()
            ->pluck('cache.id')
            ->all();

        if ($cacheIds !== []) {
            $database->table('laravel_ai_router_provider_model_caches')
                ->whereIn('id', $cacheIds)
                ->update([
                    'auto_enabled' => true,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        $connection = config('laravel-ai-router.database.connection') ?: 'laravel-ai-router';
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('laravel_ai_router_provider_model_caches')
            || ! $schema->hasColumn('laravel_ai_router_provider_model_caches', 'auto_enabled')) {
            return;
        }

        $schema->table('laravel_ai_router_provider_model_caches', function (Blueprint $table): void {
            $table->dropIndex('laravel_ai_router_model_cache_key_auto_enabled_idx');
            $table->dropIndex('laravel_ai_router_model_cache_provider_auto_enabled_idx');
            $table->dropColumn('auto_enabled');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'));

        if (! $schema->hasTable('laravel_ai_router_provider_definitions')) {
            return;
        }

        $schema->table('laravel_ai_router_provider_definitions', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('laravel_ai_router_provider_definitions', 'declared_models')) {
                $table->json('declared_models')->nullable()->after('requires_placeholder_key');
            }

            if (! $schema->hasColumn('laravel_ai_router_provider_definitions', 'models_endpoint_enabled')) {
                $table->boolean('models_endpoint_enabled')->default(true)->after('declared_models');
            }

            if (! $schema->hasColumn('laravel_ai_router_provider_definitions', 'validation_method')) {
                $table->string('validation_method', 20)->default('models')->after('models_endpoint_enabled');
            }

            if (! $schema->hasColumn('laravel_ai_router_provider_definitions', 'validation_model')) {
                $table->string('validation_model')->nullable()->after('validation_method');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'));

        if (! $schema->hasTable('laravel_ai_router_provider_definitions')) {
            return;
        }

        $schema->table('laravel_ai_router_provider_definitions', function (Blueprint $table) use ($schema): void {
            foreach (['validation_model', 'validation_method', 'models_endpoint_enabled', 'declared_models'] as $column) {
                if ($schema->hasColumn('laravel_ai_router_provider_definitions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

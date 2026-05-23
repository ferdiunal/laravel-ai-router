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

        if ($schema->hasTable('laravel_ai_router_provider_model_caches')) {
            return;
        }

        $schema->create('laravel_ai_router_provider_model_caches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_key_id')->constrained('laravel_ai_router_provider_keys')->cascadeOnDelete();
            $table->string('platform');
            $table->string('provider_label');
            $table->string('model_id');
            $table->string('display_name')->nullable();
            $table->unsignedInteger('context_window')->nullable();
            $table->unsignedInteger('rpm_limit')->nullable();
            $table->unsignedInteger('rpd_limit')->nullable();
            $table->unsignedInteger('tpm_limit')->nullable();
            $table->unsignedInteger('tpd_limit')->nullable();
            $table->string('budget_label')->nullable();
            $table->boolean('supports_tools')->nullable();
            $table->boolean('is_free')->default(true);
            $table->boolean('enabled')->default(true);
            $table->string('source')->default('live');
            $table->json('raw_metadata')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_key_id', 'model_id'], 'laravel_ai_router_model_cache_key_model_unique');
            $table->index(['platform', 'provider_label', 'enabled'], 'laravel_ai_router_model_cache_provider_label_idx');
            $table->index(['model_id', 'enabled'], 'laravel_ai_router_model_cache_model_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'))->dropIfExists('laravel_ai_router_provider_model_caches');
    }
};

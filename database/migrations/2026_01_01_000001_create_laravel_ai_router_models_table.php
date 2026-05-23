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

        if ($schema->hasTable('laravel_ai_router_models')) {
            return;
        }

        $schema->create('laravel_ai_router_models', function (Blueprint $table): void {
            $table->id();
            $table->string('platform');
            $table->string('model_id');
            $table->string('display_name');
            $table->unsignedInteger('intelligence_rank');
            $table->unsignedInteger('speed_rank')->nullable();
            $table->unsignedInteger('rpm_limit')->nullable();
            $table->unsignedInteger('rpd_limit')->nullable();
            $table->unsignedInteger('tpm_limit')->nullable();
            $table->unsignedInteger('tpd_limit')->nullable();
            $table->string('budget_label')->nullable();
            $table->unsignedInteger('context_window')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['platform', 'model_id'], 'laravel_ai_router_models_platform_model_unique');
            $table->index(['enabled', 'platform'], 'laravel_ai_router_models_enabled_platform_idx');
        });
    }

    public function down(): void
    {
        Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'))->dropIfExists('laravel_ai_router_models');
    }
};

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

        if ($schema->hasTable('laravel_ai_router_fallbacks')) {
            return;
        }

        $schema->create('laravel_ai_router_fallbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('laravel_ai_router_model_id')->constrained('laravel_ai_router_models')->cascadeOnDelete();
            $table->unsignedInteger('priority');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('penalty')->default(0);
            $table->timestamp('penalty_updated_at')->nullable();
            $table->timestamps();

            $table->unique('laravel_ai_router_model_id', 'laravel_ai_router_fallback_model_unique');
            $table->index(['enabled', 'priority'], 'laravel_ai_router_fallback_enabled_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'))->dropIfExists('laravel_ai_router_fallbacks');
    }
};

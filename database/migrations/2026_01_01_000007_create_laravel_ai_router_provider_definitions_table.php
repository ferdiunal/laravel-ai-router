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

        if ($schema->hasTable('laravel_ai_router_provider_definitions')) {
            return;
        }

        $schema->create('laravel_ai_router_provider_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('platform');
            $table->string('name');
            $table->string('adapter')->default('openai-compatible');
            $table->string('base_url');
            $table->json('headers')->nullable();
            $table->unsignedInteger('timeout_ms')->default(15000);
            $table->boolean('requires_placeholder_key')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique('platform', 'laravel_ai_router_provider_definitions_platform_unique');
            $table->index(['enabled', 'adapter'], 'laravel_ai_router_provider_definitions_state_idx');
        });
    }

    public function down(): void
    {
        Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'))->dropIfExists('laravel_ai_router_provider_definitions');
    }
};

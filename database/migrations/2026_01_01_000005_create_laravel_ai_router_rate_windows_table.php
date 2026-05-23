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

        if ($schema->hasTable('laravel_ai_router_rate_windows')) {
            return;
        }

        $schema->create('laravel_ai_router_rate_windows', function (Blueprint $table): void {
            $table->id();
            $table->string('platform');
            $table->string('model_id');
            $table->foreignId('provider_key_id')->nullable()->constrained('laravel_ai_router_provider_keys')->nullOnDelete();
            $table->string('window_type');
            $table->timestamp('window_starts_at')->nullable();
            $table->timestamp('window_ends_at')->nullable();
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('token_count')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'model_id', 'provider_key_id', 'window_type', 'window_starts_at'], 'laravel_ai_router_rate_window_unique');
            $table->index('cooldown_until', 'laravel_ai_router_rate_cooldown_idx');
            $table->index(['window_type', 'window_ends_at'], 'laravel_ai_router_rate_type_window_end_idx');
            $table->index(['platform', 'model_id', 'provider_key_id', 'window_type', 'window_ends_at'], 'laravel_ai_router_rate_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'))->dropIfExists('laravel_ai_router_rate_windows');
    }
};

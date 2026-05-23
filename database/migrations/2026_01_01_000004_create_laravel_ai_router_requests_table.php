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

        if ($schema->hasTable('laravel_ai_router_requests')) {
            return;
        }

        $schema->create('laravel_ai_router_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id')->nullable();
            $table->string('platform');
            $table->string('provider_label')->nullable();
            $table->string('model_id');
            $table->foreignId('provider_key_id')->nullable()->constrained('laravel_ai_router_provider_keys')->nullOnDelete();
            $table->string('status');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('error_type')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error_category')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at', 'laravel_ai_router_requests_created_idx');
            $table->index(['created_at', 'status'], 'laravel_ai_router_requests_created_status_idx');
            $table->index(['platform', 'model_id'], 'laravel_ai_router_requests_platform_model_idx');
            $table->index(['platform', 'created_at'], 'laravel_ai_router_requests_platform_created_idx');
            $table->index(['platform', 'provider_label', 'created_at'], 'laravel_ai_router_requests_provider_label_idx');
            $table->index(['platform', 'model_id', 'created_at'], 'laravel_ai_router_requests_model_created_idx');
            $table->index(['provider_key_id', 'created_at'], 'laravel_ai_router_requests_key_created_idx');
            $table->index('status', 'laravel_ai_router_requests_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'))->dropIfExists('laravel_ai_router_requests');
    }
};

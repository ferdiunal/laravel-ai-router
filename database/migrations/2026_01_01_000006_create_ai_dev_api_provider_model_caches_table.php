<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection((config('ai-dev-api.database.connection') ?: 'ai-dev-api'));

        if ($schema->hasTable('ai_dev_api_provider_model_caches')) {
            return;
        }

        $schema->create('ai_dev_api_provider_model_caches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_key_id')->constrained('ai_dev_api_provider_keys')->cascadeOnDelete();
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

            $table->unique(['provider_key_id', 'model_id'], 'ai_dev_model_cache_key_model_unique');
            $table->index(['platform', 'provider_label', 'enabled'], 'ai_dev_model_cache_provider_label_idx');
            $table->index(['model_id', 'enabled'], 'ai_dev_model_cache_model_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::connection((config('ai-dev-api.database.connection') ?: 'ai-dev-api'))->dropIfExists('ai_dev_api_provider_model_caches');
    }
};

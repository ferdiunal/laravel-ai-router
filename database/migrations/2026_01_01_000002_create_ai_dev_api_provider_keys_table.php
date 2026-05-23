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

        if ($schema->hasTable('ai_dev_api_provider_keys')) {
            return;
        }

        $schema->create('ai_dev_api_provider_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('platform');
            $table->string('label');
            $table->text('encrypted_key');
            $table->string('status')->default('unknown');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('models_cached_at')->nullable();
            $table->timestamp('models_cache_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'label'], 'ai_dev_keys_platform_label_unique');
            $table->index(['platform', 'enabled', 'status'], 'ai_dev_keys_platform_state_idx');
        });
    }

    public function down(): void
    {
        Schema::connection((config('ai-dev-api.database.connection') ?: 'ai-dev-api'))->dropIfExists('ai_dev_api_provider_keys');
    }
};

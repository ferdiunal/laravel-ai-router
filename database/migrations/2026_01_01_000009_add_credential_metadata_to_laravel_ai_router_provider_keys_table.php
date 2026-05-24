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

        if (! $schema->hasTable('laravel_ai_router_provider_keys') || $schema->hasColumn('laravel_ai_router_provider_keys', 'credential_metadata')) {
            return;
        }

        $schema->table('laravel_ai_router_provider_keys', function (Blueprint $table): void {
            $table->json('credential_metadata')->nullable()->after('encrypted_key');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection((config('laravel-ai-router.database.connection') ?: 'laravel-ai-router'));

        if (! $schema->hasTable('laravel_ai_router_provider_keys') || ! $schema->hasColumn('laravel_ai_router_provider_keys', 'credential_metadata')) {
            return;
        }

        $schema->table('laravel_ai_router_provider_keys', function (Blueprint $table): void {
            $table->dropColumn('credential_metadata');
        });
    }
};

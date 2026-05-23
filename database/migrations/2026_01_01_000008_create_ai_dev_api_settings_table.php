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

        if ($schema->hasTable('ai_dev_api_settings')) {
            return;
        }

        $schema->create('ai_dev_api_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection((config('ai-dev-api.database.connection') ?: 'ai-dev-api'))->dropIfExists('ai_dev_api_settings');
    }
};

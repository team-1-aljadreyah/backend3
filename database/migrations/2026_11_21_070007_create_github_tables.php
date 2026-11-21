<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_github_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained('projects')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->json('repos');
            $table->timestamps();
        });

        Schema::create('github_items', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 50);
            $table->enum('kind', ['pr', 'commit']);
            $table->string('repo', 140);
            $table->string('ref', 64);
            $table->string('title', 255);
            $table->enum('state', ['open', 'merged', 'closed'])->nullable();
            $table->string('author', 120);
            $table->timestamp('at')->index();
            $table->string('url', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['repo', 'external_id']);
            $table->index(['repo', 'at']);
        });

        Schema::create('github_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id', 120)->unique();
            $table->string('event', 64);
            $table->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_deliveries');
        Schema::dropIfExists('github_items');
        Schema::dropIfExists('project_github_settings');
    }
};

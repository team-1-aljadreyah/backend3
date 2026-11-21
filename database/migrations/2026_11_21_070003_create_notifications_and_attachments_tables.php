<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 40)->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('announcement_id')->nullable()->index();
            $table->unsignedBigInteger('bug_id')->nullable()->index();
            $table->string('chat_key', 32)->nullable();
            $table->json('data');
            $table->string('dedup_key', 191)->nullable()->unique();
            $table->timestamp('at')->index();
            $table->boolean('read')->default(false)->index();
            $table->index(['user_id', 'read', 'at']);
            $table->index(['user_id', 'at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 40);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'kind']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->enum('owner_type', ['task', 'project', 'bug', 'message']);
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 200);
            $table->unsignedBigInteger('size');
            $table->string('mime', 120);
            $table->string('disk_path', 255);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('at')->useCurrent();
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};

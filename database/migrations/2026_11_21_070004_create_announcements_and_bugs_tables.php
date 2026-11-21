<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('body');
            $table->boolean('important')->default(false)->index();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['important', 'created_at']);
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('at')->useCurrent();
            $table->unique(['announcement_id', 'user_id']);
            $table->index(['announcement_id', 'at']);
        });

        Schema::create('bugs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained('project_phases')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->enum('severity', ['critical', 'high', 'medium', 'low'])->index();
            $table->enum('priority', ['high', 'medium', 'low']);
            $table->enum('status', ['reported', 'assigned', 'fixing', 'testing', 'reopened', 'closed'])->default('reported')->index();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('due')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['assignee_id', 'due']);
        });

        Schema::create('bug_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bug_id')->constrained('bugs')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('from', 255)->nullable();
            $table->string('to', 255)->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('at')->index();
            $table->index(['bug_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_events');
        Schema::dropIfExists('bugs');
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};

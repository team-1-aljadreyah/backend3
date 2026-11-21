<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained('project_phases')->nullOnDelete();
            $table->foreignId('sprint_id')->nullable()->constrained('sprints')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('start')->nullable();
            $table->date('due')->nullable()->index();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->string('status', 64)->index();
            $table->string('blocked_reason', 300)->nullable();
            $table->unsignedInteger('subtasks_done')->default(0);
            $table->unsignedInteger('subtasks_total')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['assignee_id', 'due']);
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['task_id', 'depends_on_task_id']);
            $table->index('depends_on_task_id');
        });

        Schema::create('task_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->enum('state', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->string('from_status', 64);
            $table->string('to_status', 64);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->index(['task_id', 'is_current']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['task_id', 'created_at']);
        });

        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('from', 255)->nullable();
            $table->string('to', 255)->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('at')->index();
            $table->index(['task_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_events');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_approvals');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('tasks');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            $table->text('description');
            $table->foreignId('pm_id')->constrained('users')->restrictOnDelete();
            $table->date('start')->index();
            $table->date('end')->index();
            $table->enum('state', ['planned', 'active', 'paused', 'done'])->default('active')->index();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->boolean('uses_sprints')->default(false);
            $table->timestamps();
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('project_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('key', 64);
            $table->string('name', 40);
            $table->string('name_en', 40);
            $table->enum('tone', ['new', 'todo', 'progress', 'review', 'testing', 'done', 'blocked']);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_base')->default(false);
            $table->timestamps();
            $table->unique(['project_id', 'key']);
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'position']);
        });

        Schema::create('project_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'position']);
        });

        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('name', 60);
            $table->date('start');
            $table->date('end');
            $table->timestamp('closed_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'start', 'end']);
        });

        Schema::create('project_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('from', 255)->nullable();
            $table->string('to', 255)->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('at')->index();
            $table->index(['project_id', 'at']);
        });

        Schema::create('project_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->text('description');
            $table->json('statuses');
            $table->json('phases');
            $table->boolean('uses_sprints')->default(false);
            $table->json('tasks');
            $table->string('from_project', 120)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_templates');
        Schema::dropIfExists('project_events');
        Schema::dropIfExists('sprints');
        Schema::dropIfExists('project_phases');
        Schema::dropIfExists('project_statuses');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};

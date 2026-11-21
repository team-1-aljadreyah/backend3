<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_criteria', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('items');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->json('scores');
            $table->json('criteria');
            $table->unsignedTinyInteger('total');
            $table->text('note');
            $table->json('perf');
            $table->enum('state', ['draft', 'final'])->default('draft')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'period_to']);
            $table->index('reviewer_id');
        });

        Schema::create('performance_review_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('performance_reviews')->cascadeOnDelete();
            $table->string('criterion_id', 64);
            $table->string('criterion_name', 120);
            $table->string('criterion_name_en', 120);
            $table->enum('kind', ['auto', 'manual']);
            $table->string('metric', 32)->nullable();
            $table->unsignedTinyInteger('weight');
            $table->unsignedTinyInteger('stars')->nullable();
            $table->unsignedTinyInteger('score');
            $table->unique(['review_id', 'criterion_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 64)->index();
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label', 255);
            $table->json('changes')->nullable();
            $table->timestamp('at')->useCurrent()->index();
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('performance_review_scores');
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('performance_criteria');
    }
};

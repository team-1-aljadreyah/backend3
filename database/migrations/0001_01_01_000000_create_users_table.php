<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('name_en', 80)->nullable();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('email', 191)->unique();
            $table->string('phone', 40)->nullable()->index();
            $table->enum('role', ['ceo', 'pm', 'monitor', 'lead', 'employee', 'admin'])->index();
            $table->string('title', 80)->default('');
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->boolean('active')->default(true)->index();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('login_security', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable()->index();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedTinyInteger('due_soon_days')->default(1);
            $table->unsignedTinyInteger('lock_after')->default(5);
            $table->unsignedSmallInteger('lock_minutes')->default(15);
            $table->timestamps();
        });

        Schema::create('code_sequences', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 191)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('code_sequences');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('login_security');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });
        Schema::dropIfExists('users');
        Schema::dropIfExists('teams');
    }
};

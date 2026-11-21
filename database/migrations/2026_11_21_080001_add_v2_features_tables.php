<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'custom_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('custom_role', 40)->nullable()->index()->after('role');
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'session_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('session_id', 64)->nullable()->index()->after('abilities');
            });
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'ip')) {
                $table->string('ip', 45)->nullable()->index()->after('at');
            }
            if (! Schema::hasColumn('audit_logs', 'user_agent')) {
                $table->string('user_agent', 300)->nullable()->after('ip');
            }
            if (! Schema::hasColumn('audit_logs', 'session_id')) {
                $table->string('session_id', 64)->nullable()->index()->after('user_agent');
            }
            if (! Schema::hasColumn('audit_logs', 'request_id')) {
                $table->string('request_id', 64)->nullable()->index()->after('session_id');
            }
        });

        if (! Schema::hasTable('avatars')) {
            Schema::create('avatars', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('disk_path', 255);
                $table->string('mime', 64);
                $table->unsignedInteger('size');
                $table->unsignedSmallInteger('width');
                $table->unsignedSmallInteger('height');
                $table->string('cache_key', 64)->unique();
                $table->timestamp('created_at')->useCurrent();
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('chat_groups')) {
            Schema::create('chat_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('chat_group_members')) {
            Schema::create('chat_group_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained('chat_groups')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->unique(['group_id', 'user_id']);
                $table->index(['group_id', 'position']);
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('monitor_notes')) {
            Schema::create('monitor_notes', function (Blueprint $table) {
                $table->id();
                $table->enum('kind', ['report', 'feedback', 'problem', 'request'])->index();
                $table->string('title', 160);
                $table->text('body');
                $table->enum('about_type', ['team', 'person', 'project', 'general']);
                $table->unsignedBigInteger('about_id')->nullable();
                $table->string('about_label', 255)->nullable();
                $table->string('about_label_en', 255)->nullable();
                $table->enum('tone', ['praise', 'improve'])->nullable();
                $table->string('subject', 120)->nullable();
                $table->json('people')->nullable();
                $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('sent_at')->nullable()->index();
                $table->enum('ceo_state', ['new', 'seen', 'done'])->nullable();
                $table->text('ceo_reply')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->json('facts')->nullable();
                $table->index(['author_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('advanced_settings')) {
            Schema::create('advanced_settings', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->json('rules');
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('advanced_settings_logs')) {
            Schema::create('advanced_settings_logs', function (Blueprint $table) {
                $table->id();
                $table->timestamp('at')->useCurrent()->index();
                $table->foreignId('by_id')->constrained('users')->restrictOnDelete();
                $table->string('by_name', 120);
                $table->string('by_name_en', 120)->nullable();
                $table->string('how', 20)->nullable();
                $table->json('changes');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('advanced_settings_logs');
        Schema::dropIfExists('advanced_settings');
        Schema::dropIfExists('monitor_notes');
        Schema::dropIfExists('chat_group_members');
        Schema::dropIfExists('chat_groups');
        Schema::dropIfExists('avatars');

        Schema::table('audit_logs', function (Blueprint $table) {
            foreach (['ip', 'user_agent', 'session_id', 'request_id'] as $col) {
                if (Schema::hasColumn('audit_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasColumn('personal_access_tokens', 'session_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('session_id');
            });
        }

        if (Schema::hasColumn('users', 'custom_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('custom_role');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'birth_date')) {
                $table->date('birth_date')->nullable()->index()->after('custom_role');
            }
            if (! Schema::hasColumn('users', 'preferred_locale')) {
                $table->string('preferred_locale', 8)->default('ar')->after('birth_date');
            }
        });

        if (! Schema::hasColumn('attachments', 'voice')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->json('voice')->nullable()->after('mime');
            });
        }

        if (! Schema::hasColumn('notifications', 'team_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->foreignId('team_id')
                    ->nullable()
                    ->after('chat_key')
                    ->constrained('teams')
                    ->nullOnDelete();
            });
        }

        Schema::table('monitor_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('monitor_notes', 'triage')) {
                $table->enum('triage', ['pending', 'forwarded', 'answered'])
                    ->nullable()
                    ->index()
                    ->after('facts');
            }
            if (! Schema::hasColumn('monitor_notes', 'forward_note')) {
                $table->text('forward_note')->nullable()->after('triage');
            }
            if (! Schema::hasColumn('monitor_notes', 'monitor_reply')) {
                $table->text('monitor_reply')->nullable()->after('forward_note');
            }
            if (! Schema::hasColumn('monitor_notes', 'triaged_by')) {
                $table->foreignId('triaged_by')
                    ->nullable()
                    ->after('monitor_reply')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('monitor_notes', 'triaged_at')) {
                $table->timestamp('triaged_at')->nullable()->after('triaged_by');
            }
        });

        if (! Schema::hasTable('monitor_note_shares')) {
            Schema::create('monitor_note_shares', function (Blueprint $table) {
                $table->id();
                $table->foreignId('note_id')->constrained('monitor_notes')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('label', 255);
                $table->string('label_en', 255)->nullable();
                $table->timestamp('shared_at')->useCurrent();
                $table->timestamp('seen_at')->nullable();
                $table->unique(['note_id', 'user_id']);
                $table->index(['user_id', 'shared_at']);
            });
        }

        if (! Schema::hasTable('ratings')) {
            Schema::create('ratings', function (Blueprint $table) {
                $table->id();
                $table->enum('subject_type', ['team', 'person']);
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('rater_id')->constrained('users')->restrictOnDelete();
                $table->unsignedTinyInteger('score');
                $table->string('reason_type', 32);
                $table->text('reason');
                $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->string('project_name', 160)->nullable();
                $table->string('project_name_en', 160)->nullable();
                $table->date('period_from')->nullable();
                $table->date('period_to')->nullable();
                $table->boolean('private')->default(false);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('edited_at')->nullable();
                $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->index(['subject_type', 'subject_id', 'created_at']);
                $table->index(['rater_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('auth_sessions')) {
            Schema::create('auth_sessions', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('token_id')->nullable()->index();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 300)->nullable();
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('last_seen_at')->useCurrent()->index();
                $table->timestamp('ended_at')->nullable()->index();
                $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('end_reason', 24)->nullable();
                $table->index(['user_id', 'ended_at']);
            });
        }

        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('session_id', 64)->nullable()->index();
                $table->char('endpoint_hash', 64)->unique();
                $table->text('endpoint');
                $table->string('p256dh', 255);
                $table->string('auth', 255);
                $table->string('device', 120);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('last_used_at')->nullable()->index();
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('monitor_note_shares');

        if (Schema::hasTable('monitor_notes')) {
            Schema::table('monitor_notes', function (Blueprint $table) {
                foreach (['triaged_by', 'triaged_at', 'monitor_reply', 'forward_note', 'triage'] as $col) {
                    if (Schema::hasColumn('monitor_notes', $col)) {
                        if ($col === 'triaged_by') {
                            $table->dropConstrainedForeignId('triaged_by');
                        } else {
                            $table->dropColumn($col);
                        }
                    }
                }
            });
        }

        if (Schema::hasColumn('notifications', 'team_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropConstrainedForeignId('team_id');
            });
        }

        if (Schema::hasColumn('attachments', 'voice')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->dropColumn('voice');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['preferred_locale', 'birth_date'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

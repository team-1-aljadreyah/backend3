<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_low_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_high_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_low_id', 'user_high_id']);
            $table->index('user_high_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('chat_key', 32)->index();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->restrictOnDelete();
            $table->json('mentions');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['chat_key', 'id']);
        });

        Schema::create('chat_reads', function (Blueprint $table) {
            $table->id();
            $table->string('chat_key', 32);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['chat_key', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reads');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('direct_conversations');
    }
};

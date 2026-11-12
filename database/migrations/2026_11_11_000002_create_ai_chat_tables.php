<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('enrollment_id')
                ->nullable()
                ->constrained('enrollments')
                ->nullOnDelete();
            $table->foreignUlid('section_id')
                ->nullable()
                ->constrained('sections')
                ->nullOnDelete();
            $table->string('title', 100)->default('新しい相談');
            $table->boolean('auto_title_enabled')->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'last_message_at']);
            $table->index(['user_id', 'section_id']);
            $table->index('enrollment_id');
        });

        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ai_chat_conversation_id')
                ->constrained('ai_chat_conversations')
                ->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('status', 20)->default('completed');
            $table->longText('content');
            $table->string('model_name', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->text('error_detail')->nullable();
            $table->timestamps();

            $table->index(['ai_chat_conversation_id', 'created_at']);
            $table->index(['ai_chat_conversation_id', 'status']);
        });

        Schema::create('ai_chat_daily_usages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_daily_usages');
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_conversations');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('meeting_pack_id')
                ->nullable()
                ->constrained('meeting_packs')
                ->nullOnDelete();
            $table->string('meeting_pack_name', 100);
            $table->unsignedSmallInteger('meeting_count');
            $table->unsignedInteger('amount');
            $table->char('currency', 3)->default('JPY');
            $table->string('status', 20)->default('pending');
            $table->string('stripe_checkout_session_id', 255)->nullable()->unique();
            $table->string('stripe_payment_intent_id', 255)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropIndex(['related_payment_id']);
            $table->foreign('related_payment_id')
                ->references('id')
                ->on('payments')
                ->restrictOnDelete();
            $table->unique('related_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropForeign(['related_payment_id']);
            $table->dropUnique(['related_payment_id']);
            $table->index('related_payment_id');
        });

        Schema::dropIfExists('payments');
    }
};

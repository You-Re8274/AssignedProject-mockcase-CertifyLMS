<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dateTime('day_before_reminder_sent_at')
                ->nullable()
                ->after('completed_at');
            $table->dateTime('one_hour_before_reminder_sent_at')
                ->nullable()
                ->after('day_before_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn([
                'day_before_reminder_sent_at',
                'one_hour_before_reminder_sent_at',
            ]);
        });
    }
};

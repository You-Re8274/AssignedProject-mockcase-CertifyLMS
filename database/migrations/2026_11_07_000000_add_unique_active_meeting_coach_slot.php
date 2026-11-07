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
            $table->dateTime('occupied_slot_at')
                ->nullable()
                ->virtualAs("CASE WHEN status IN ('reserved', 'completed') THEN scheduled_at ELSE NULL END");
            $table->unique(
                ['coach_id', 'occupied_slot_at'],
                'meetings_active_coach_slot_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique('meetings_active_coach_slot_unique');
            $table->dropColumn('occupied_slot_at');
        });
    }
};

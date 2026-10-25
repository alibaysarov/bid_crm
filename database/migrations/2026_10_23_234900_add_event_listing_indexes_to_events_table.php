<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->index(['event_time', 'id'], 'events_time_id_index');
            $table->index(['event_type', 'event_time', 'id'], 'events_type_time_id_index');
            $table->index(['status', 'event_time', 'id'], 'events_status_time_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_time_id_index');
            $table->dropIndex('events_type_time_id_index');
            $table->dropIndex('events_status_time_id_index');
        });
    }
};

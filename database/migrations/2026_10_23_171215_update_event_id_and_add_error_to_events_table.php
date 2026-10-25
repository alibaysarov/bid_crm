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
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'event_id')) {
                $table->string('event_id')->nullable()->change();
            }

            if (! Schema::hasColumn('events', 'error')) {
                $table->text('error')->nullable();
            }

            if (Schema::hasIndex('events', ['event_id', 'event_type'], 'unique')) {
                $table->dropUnique(['event_id', 'event_type']);
            }

            if (! Schema::hasIndex('events', ['event_id'], 'unique')) {
                $table->unique('event_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasIndex('events', ['event_id'], 'unique')) {
                $table->dropUnique(['event_id']);
            }

            if (! Schema::hasIndex('events', ['event_id', 'event_type'], 'unique')) {
                $table->unique(['event_id', 'event_type']);
            }

            if (Schema::hasColumn('events', 'error')) {
                $table->dropColumn('error');
            }

            if (Schema::hasColumn('events', 'event_id')) {
                $table->integer('event_id')->nullable()->change();
            }
        });
    }
};

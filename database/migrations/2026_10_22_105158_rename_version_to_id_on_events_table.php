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
        if (! Schema::hasTable('events') || ! Schema::hasColumn('events', 'version') || Schema::hasColumn('events', 'id')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->renameColumn('version', 'id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('events') || ! Schema::hasColumn('events', 'id') || Schema::hasColumn('events', 'version')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->renameColumn('id', 'version');
        });
    }
};

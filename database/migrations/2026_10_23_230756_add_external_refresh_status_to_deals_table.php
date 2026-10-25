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
        Schema::table('deals', function (Blueprint $table) {
            $table->string('external_refresh_status')->nullable();
            $table->text('external_refresh_error')->nullable();
            $table->timestampTz('external_refresh_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'external_refresh_status',
                'external_refresh_error',
                'external_refresh_updated_at',
            ]);
        });
    }
};

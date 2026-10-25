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
        if (Schema::hasTable('events')) {
            return;
        }

        Schema::create('events', function (Blueprint $table) {
            $table->integer('event_id')->nullable();
            $table->string('event_type', 64)->nullable();
            $table->timestamp('event_time')->nullable();
            $table->jsonb('data')->nullable();
            $table->string('status', 32)->nullable();
            $table->id();

            $table->unique('event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

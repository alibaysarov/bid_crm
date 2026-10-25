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
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('event_type', 100);
            $table->jsonb('payload');
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id'], 'outbox_status_available_id_index');
            $table->index(['status', 'locked_at'], 'outbox_status_locked_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};

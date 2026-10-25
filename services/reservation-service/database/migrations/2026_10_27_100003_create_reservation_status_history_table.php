<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $statuses = ['pending', 'approved', 'completed', 'rejected', 'cancelled', 'expired'];

        Schema::create('reservation_status_history', function (Blueprint $table) use ($statuses) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->enum('from_status', $statuses)->nullable(); // null for the first row
            $table->enum('to_status', $statuses);
            $table->unsignedBigInteger('actor_id')->nullable(); // user id from identity-service; null for the system
            $table->string('reason', 255)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['reservation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_history');
    }
};

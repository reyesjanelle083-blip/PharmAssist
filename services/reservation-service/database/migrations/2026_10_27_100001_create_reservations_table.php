<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * user_id, branch_id and (in reservation_items) medicine_id are plain integers owned by other services:
     * there is deliberately no foreign key to another schema.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('claim_code', 16)->unique(); // random and non-guessable, for example PA-7K3Q9X2M
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id');
            $table->enum('status', ['pending', 'approved', 'completed', 'rejected', 'cancelled', 'expired'])->default('pending');
            $table->enum('ocr_status', ['not_required', 'not_processed', 'success', 'low_confidence', 'unavailable'])->default('not_required');
            $table->text('ocr_text')->nullable();
            $table->decimal('ocr_confidence', 4, 3)->nullable(); // 0.000 to 1.000
            $table->string('prescription_path')->nullable(); // private disk only, never returned by the API
            $table->string('hold_ref', 64)->unique(); // the reservation_ref sent to inventory-service
            $table->string('notes', 500)->nullable();
            $table->dateTime('expires_at');
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};

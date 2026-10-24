<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_holds', function (Blueprint $table) {
            $table->id();
            // One row per reserved line. A reservation with several items has several rows sharing reservation_ref.
            $table->string('hold_ref', 64)->unique();
            $table->string('reservation_ref', 64)->index();
            $table->foreignId('branch_inventory_id')->constrained('branch_inventory')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('status', ['held', 'committed', 'released', 'expired'])->default('held');
            $table->timestamp('expires_at');

            // Replaying the same reservation must not hold the same stock row twice.
            $table->unique(['reservation_ref', 'branch_inventory_id']);
            // Used by the expiry job: held rows that are past expires_at.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_holds');
    }
};

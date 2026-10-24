<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_inventory_id')->constrained('branch_inventory')->restrictOnDelete();
            $table->integer('delta'); // signed change of stock_quantity
            $table->enum('reason', ['manual', 'hold_commit', 'sync', 'correction']);
            // User id from identity-service. Plain number, no foreign key (different service).
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('request_id', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_inventory_id', 'created_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};

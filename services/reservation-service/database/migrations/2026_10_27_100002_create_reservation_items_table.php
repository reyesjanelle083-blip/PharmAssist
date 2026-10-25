<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->unsignedBigInteger('medicine_id'); // owned by inventory-service: no foreign key
            $table->string('medicine_name', 255); // snapshot at reservation time
            $table->decimal('unit_price', 10, 2); // snapshot at reservation time
            $table->unsignedSmallInteger('quantity');

            $table->unique(['reservation_id', 'medicine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_items');
    }
};

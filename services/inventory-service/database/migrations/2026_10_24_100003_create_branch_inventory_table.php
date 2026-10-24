<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('min_threshold')->default(10);
            $table->string('batch_no', 50)->nullable();
            $table->date('expiration_date')->nullable();
            $table->enum('status', ['available', 'low_stock', 'out_of_stock'])->default('out_of_stock');
            // Optimistic lock: incremented on every update; a stale value is rejected (409 STALE_VERSION).
            $table->unsignedInteger('version')->default(1);

            $table->unique(['branch_id', 'medicine_id']);
            $table->index('status');
        });

        // Stock can never go negative and can never be reserved beyond what exists.
        // CHECK constraints are enforced by MySQL 8.0.16+ (the project uses MySQL 8); SQLite cannot add them afterwards.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE branch_inventory ADD CONSTRAINT chk_branch_inventory_quantities CHECK (stock_quantity >= 0 AND reserved_quantity >= 0 AND reserved_quantity <= stock_quantity)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_inventory');
    }
};

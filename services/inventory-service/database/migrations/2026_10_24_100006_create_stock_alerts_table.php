<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_inventory_id')->constrained('branch_inventory')->restrictOnDelete();
            $table->enum('alert_type', ['low_stock', 'critical_stock', 'out_of_stock']);
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();

            $table->index(['is_resolved', 'created_at']);
            $table->index('branch_inventory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};

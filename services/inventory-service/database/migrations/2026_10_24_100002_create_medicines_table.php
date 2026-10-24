<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('generic_name', 150);
            // Empty string (not NULL) for unbranded generics, so the UNIQUE key below really prevents duplicates.
            $table->string('brand_name', 100)->default('');
            $table->string('category', 100)->index();
            $table->string('strength', 50);
            $table->string('dosage_form', 50);
            $table->boolean('prescription_required')->default(false);
            $table->string('image_path', 255)->nullable();
            $table->text('counseling_summary')->nullable();

            $table->unique(['generic_name', 'brand_name', 'strength', 'dosage_form'], 'medicines_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};

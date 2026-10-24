<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->enum('role', [
                'customer',
                'pharmacy_technician',
                'pharmacy_assistant',
                'branch_manager',
                'ceo',
            ])->default('customer');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('contact_no', 20)->nullable();
            // Reference to a branch owned by inventory-service. Deliberately no foreign key.
            $table->unsignedInteger('branch_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->enum('verification_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('rejection_reason', 500)->nullable();
            // Private storage path; cleared after the CEO decision.
            $table->string('id_document_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();

            $table->index(['role', 'branch_id']);
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

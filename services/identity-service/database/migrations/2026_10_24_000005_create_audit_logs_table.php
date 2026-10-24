<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // No foreign key: audit rows must outlive the user they describe.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('entity', 100);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('actor_id');
            $table->index(['entity', 'entity_id']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('key', 128);
            $table->char('request_hash', 64); // sha256 of the request (including the prescription file)
            $table->unsignedSmallInteger('status_code')->nullable(); // null while the first request is still running
            $table->json('response_json')->nullable(); // the `data` of the stored response
            $table->timestamp('created_at')->useCurrent();

            // The unique key is what makes two simultaneous requests safe: only one insert can win.
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 128)->nullable()->index();
            // SOAP was dropped and Nominatim is not used; these are the providers this service talks to.
            $table->enum('provider', ['osrm', 'openfda', 'ocr', 'smtp']);
            $table->string('operation', 50);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->enum('outcome', ['success', 'fallback', 'failure', 'timeout']);
            $table->unsignedInteger('latency_ms')->default(0);
            // A short fixed reason code (for example "timeout"), never a raw exception message or a URL.
            $table->string('error_message', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'created_at']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};

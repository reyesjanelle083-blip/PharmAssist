<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_cache', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 64)->unique(); // sha256 hex
            $table->json('payload');
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            // The inventory feed translator is a provider of its own in the log.
            $table->enum('provider', ['osrm', 'openfda', 'ocr', 'smtp', 'xml_feed'])->change();
        });
    }

    public function down(): void
    {
        // Rows with the new value cannot exist in the old enum.
        DB::table('integration_logs')->where('provider', 'xml_feed')->delete();

        Schema::table('integration_logs', function (Blueprint $table) {
            $table->enum('provider', ['osrm', 'openfda', 'ocr', 'smtp'])->change();
        });
    }
};

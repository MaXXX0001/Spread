<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('traffic_sources', function (Blueprint $table) {
            // Names of the macro params that reports read as the zone and the creative of a click.
            $table->string('zone_param', 64)->nullable();
            $table->string('creative_param', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('traffic_sources', function (Blueprint $table) {
            $table->dropColumn(['zone_param', 'creative_param']);
        });
    }
};

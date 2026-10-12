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
        Schema::table('conversions', function (Blueprint $table) {
            $table->decimal('payout_base', 14, 4)->nullable()->after('currency');
            $table->char('base_currency', 3)->nullable()->after('payout_base');
        });

        // Used when a postback brings a payout without a currency.
        Schema::table('cpa_networks', function (Blueprint $table) {
            $table->char('default_currency', 3)->default('USD');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversions', function (Blueprint $table) {
            $table->dropColumn(['payout_base', 'base_currency']);
        });

        Schema::table('cpa_networks', function (Blueprint $table) {
            $table->dropColumn('default_currency');
        });
    }
};

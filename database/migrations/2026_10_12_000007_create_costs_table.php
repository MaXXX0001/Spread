<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            // A day in the report timezone, not a UTC timestamp.
            $table->date('date');
            // Null: the cost of the whole campaign for the day.
            $table->string('zone')->nullable();
            $table->decimal('amount', 14, 4);
            $table->char('currency', 3);
            $table->decimal('amount_base', 14, 4)->nullable();
            $table->char('base_currency', 3)->nullable();
            $table->timestamps();

            $table->index(['date', 'campaign_id']);
        });

        // NULLS NOT DISTINCT: one whole-campaign cost (zone null) per campaign and day, too.
        DB::statement('CREATE UNIQUE INDEX costs_campaign_id_date_zone_unique ON costs (campaign_id, date, zone) NULLS NOT DISTINCT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('costs');
    }
};

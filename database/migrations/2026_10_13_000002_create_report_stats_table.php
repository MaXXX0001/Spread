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
        // Rows of an hour are always deleted and inserted again, so there is no unique key.
        Schema::create('report_stats', function (Blueprint $table) {
            // UTC; the day and hour in the report timezone are derived when reports are queried.
            $table->timestamp('hour', 0);
            // No FK, as in clicks.
            $table->bigInteger('campaign_id');
            // Dimensions hold '' instead of null, so grouping and matching cost zones never deal with nulls.
            $table->text('zone');
            $table->text('creative');
            $table->string('country_code', 2);
            $table->string('device_type');
            $table->string('os_name');
            $table->integer('clicks');
            $table->integer('unique_clicks');
            $table->integer('bot_clicks');
            $table->integer('conversions');
            $table->integer('approved');
            $table->integer('hold');
            $table->integer('rejected');
            $table->decimal('revenue', 16, 4);
            $table->decimal('expected_revenue', 16, 4);
            // Approved and hold conversions with a payout that has no base currency amount yet.
            $table->integer('no_rate');

            $table->index('hour');
            $table->index(['campaign_id', 'hour']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_stats');
    }
};

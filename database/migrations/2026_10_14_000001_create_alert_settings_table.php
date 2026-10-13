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
        // A single row; until it is saved, the model's defaults apply.
        Schema::create('alert_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->nullable();
            $table->unsignedSmallInteger('reminder_hours');
            $table->boolean('roi_enabled');
            // In the base currency.
            $table->decimal('roi_min_cost', 14, 4);
            // Percent; an alert fires below it.
            $table->decimal('roi_max', 10, 2);
            $table->boolean('silence_enabled');
            $table->unsignedSmallInteger('silence_hours');
            $table->unsignedInteger('silence_min_clicks');
            $table->boolean('cr_enabled');
            // Percent; an alert fires above it.
            $table->decimal('cr_max', 10, 2);
            $table->unsignedInteger('cr_min_clicks');
            $table->boolean('buffer_enabled');
            $table->unsignedInteger('buffer_max_age_minutes');
            $table->unsignedInteger('buffer_max_length');
            $table->boolean('errors_enabled');
            $table->boolean('daily_enabled');
            // HH:MM in the report timezone.
            $table->string('daily_time', 5);
            $table->date('daily_sent_on')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_settings');
    }
};

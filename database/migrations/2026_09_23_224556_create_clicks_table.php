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
        Schema::create('clicks', function (Blueprint $table) {
            $table->ulid('click_id')->primary();
            $table->timestamp('clicked_at', 3);
            $table->string('status', 32);
            // No FKs: a click still in the stream must insert even if its campaign was deleted meanwhile.
            $table->bigInteger('campaign_id');
            $table->bigInteger('offer_id');
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('referer')->nullable();
            $table->jsonb('params');
            $table->char('country_code', 2)->nullable();
            $table->string('device_type')->nullable();
            $table->string('os_name')->nullable();
            $table->string('os_version')->nullable();
            $table->string('browser_name')->nullable();
            $table->string('browser_version')->nullable();
            $table->boolean('is_bot')->default(false);
            $table->boolean('is_duplicate')->default(false);
            $table->timestamp('created_at');

            $table->index('clicked_at');
            $table->index(['campaign_id', 'clicked_at']);
            $table->index(['ip', 'clicked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clicks');
    }
};

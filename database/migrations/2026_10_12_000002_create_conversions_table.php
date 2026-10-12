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
        Schema::create('conversions', function (Blueprint $table) {
            $table->id();
            $table->ulid('click_id')->unique();
            $table->foreignId('cpa_network_id')->constrained()->restrictOnDelete();
            // No FKs, as in clicks; null until the click reaches the database (an orphan conversion).
            $table->bigInteger('campaign_id')->nullable();
            $table->bigInteger('offer_id')->nullable();
            $table->string('status', 16);
            $table->decimal('payout', 12, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('txid')->nullable();
            $table->timestamp('last_postback_at', 3);
            $table->timestamp('status_changed_at', 3);
            $table->timestamps();

            $table->index('status_changed_at');
            $table->index(['campaign_id', 'status_changed_at']);
        });

        DB::statement('CREATE INDEX conversions_orphan_click_id_index ON conversions (click_id) WHERE campaign_id IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversions');
    }
};

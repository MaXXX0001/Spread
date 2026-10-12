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
        Schema::create('postbacks', function (Blueprint $table) {
            $table->ulid('postback_id')->primary();
            $table->timestamp('received_at', 3);
            // Null for an unknown key; the key itself is never stored.
            $table->foreignId('cpa_network_id')->nullable()->constrained()->nullOnDelete();
            $table->ipAddress('ip')->nullable();
            $table->jsonb('params');
            $table->string('state', 16);
            $table->text('reason')->nullable();
            $table->char('click_id', 26)->nullable();
            $table->foreignId('conversion_id')->nullable()->constrained();
            $table->timestamp('processed_at', 3)->nullable();
            $table->timestamp('created_at');

            $table->index('received_at');
            $table->index(['state', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postbacks');
    }
};

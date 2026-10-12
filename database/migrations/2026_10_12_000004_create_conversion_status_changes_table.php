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
        Schema::create('conversion_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversion_id')->constrained();
            $table->foreignUlid('postback_id')->constrained('postbacks', 'postback_id');
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->decimal('payout', 12, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('changed_at', 3);
            $table->timestamp('created_at');

            $table->index(['conversion_id', 'changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversion_status_changes');
    }
};

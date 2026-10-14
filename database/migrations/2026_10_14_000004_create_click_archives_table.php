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
        // Months whose clicks left the database; their report stats are frozen.
        Schema::create('click_archives', function (Blueprint $table) {
            $table->id();
            // The first day of the month (UTC).
            $table->date('month');
            $table->unsignedInteger('clicks');
            $table->string('file');
            $table->timestamp('archived_at');

            $table->index('month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('click_archives');
    }
};

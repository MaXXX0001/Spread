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
        // An open problem per alert type and subject; the row is deleted once the problem is resolved.
        Schema::create('alert_states', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('subject');
            $table->timestamp('opened_at');
            // Null until the first message about the problem is delivered.
            $table->timestamp('last_sent_at')->nullable();
            $table->text('message');
            $table->timestamps();

            $table->unique(['type', 'subject']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_states');
    }
};

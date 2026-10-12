<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string KEY_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private const int KEY_LENGTH = 32;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cpa_networks', function (Blueprint $table) {
            $table->char('postback_key', self::KEY_LENGTH)->nullable()->unique();
            $table->jsonb('status_map')->default('{}');
            $table->jsonb('allowed_ips')->default('[]');
        });

        // Existing networks get a key here; the model generates it for new ones.
        $networkIds = DB::table('cpa_networks')->pluck('id');

        foreach ($networkIds as $networkId) {
            $key = $this->generateKey();

            DB::table('cpa_networks')
                ->where('id', '=', $networkId)
                ->update(['postback_key' => $key]);
        }

        Schema::table('cpa_networks', function (Blueprint $table) {
            $table->char('postback_key', self::KEY_LENGTH)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cpa_networks', function (Blueprint $table) {
            $table->dropUnique(['postback_key']);
            $table->dropColumn(['postback_key', 'status_map', 'allowed_ips']);
        });
    }

    private function generateKey(): string
    {
        $maxIndex = strlen(self::KEY_ALPHABET) - 1;
        $key = '';

        for ($i = 0; $i < self::KEY_LENGTH; $i++) {
            $index = random_int(0, $maxIndex);
            $key .= self::KEY_ALPHABET[$index];
        }

        return $key;
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_entries', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable();
            $table->unique(['user_id', 'idempotency_key'], 'wallet_entries_user_id_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_entries', function (Blueprint $table) {
            $table->dropUnique('wallet_entries_user_id_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

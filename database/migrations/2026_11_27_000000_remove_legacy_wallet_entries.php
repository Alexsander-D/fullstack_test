<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_operations') && Schema::hasColumn('wallet_operations', 'legacy_entry_id')) {
            Schema::table('wallet_operations', function (Blueprint $table) {
                $table->dropUnique(['legacy_entry_id']);
                $table->dropColumn('legacy_entry_id');
            });
        }

        Schema::dropIfExists('wallet_entries');
    }

    public function down(): void
    {
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);
            $table->string('description', 150);
            $table->decimal('amount', 12, 2);
            $table->uuid('idempotency_key')->nullable();
            $table->date('occurred_on');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'wallet_entries_user_id_idempotency_unique');
            $table->index(['user_id', 'occurred_on']);
        });

        if (Schema::hasTable('wallet_operations') && ! Schema::hasColumn('wallet_operations', 'legacy_entry_id')) {
            Schema::table('wallet_operations', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_entry_id')->nullable()->unique();
            });
        }
    }
};
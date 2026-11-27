<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('description', 255);
            $table->date('occurred_on');
            $table->foreignId('reverses_operation_id')->nullable()->constrained('wallet_operations')->restrictOnDelete();
            $table->uuid('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique('reverses_operation_id');
            $table->unique(['created_by_user_id', 'idempotency_key'], 'wallet_operations_user_key_unique');
            $table->index(['from_user_id', 'occurred_on']);
            $table->index(['to_user_id', 'occurred_on']);
        });

        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('wallet_operations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->timestamps();
            $table->unique(['operation_id', 'user_id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallet_operations');
    }
};
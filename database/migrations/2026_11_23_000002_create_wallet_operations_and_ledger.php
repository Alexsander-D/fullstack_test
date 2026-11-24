<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->unsignedBigInteger('legacy_entry_id')->nullable()->unique();
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

        DB::table('wallet_entries')->orderBy('id')->chunk(500, function ($entries): void {
            foreach ($entries as $entry) {
                if (! in_array($entry->type, ['income', 'expense'], true)) {
                    throw new RuntimeException('Um lançamento legado tem um tipo não reconhecido.');
                }

                $amountCents = $this->amountToCents((string) $entry->amount);
                $isIncome = $entry->type === 'income';
                $createdAt = $entry->created_at ?? now();
                $updatedAt = $entry->updated_at ?? $createdAt;

                $operationId = DB::table('wallet_operations')->insertGetId([
                    'created_by_user_id' => $entry->user_id,
                    'from_user_id' => $isIncome ? null : $entry->user_id,
                    'to_user_id' => $isIncome ? $entry->user_id : null,
                    'type' => $isIncome ? 'legacy_income' : 'legacy_expense',
                    'amount_cents' => $amountCents,
                    'description' => $entry->description,
                    'occurred_on' => $entry->occurred_on,
                    'legacy_entry_id' => $entry->id,
                    'idempotency_key' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ]);

                DB::table('wallet_ledger_entries')->insert([
                    'operation_id' => $operationId,
                    'user_id' => $entry->user_id,
                    'amount_cents' => $isIncome ? $amountCents : -$amountCents,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallet_operations');
    }

    private function amountToCents(string $amount): int
    {
        [$units, $cents] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $units * 100) + (int) str_pad(substr($cents, 0, 2), 2, '0');
    }
};

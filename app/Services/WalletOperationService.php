<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletOperation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class WalletOperationService
{
    /**
     * @return array{operation: WalletOperation, created: bool}
     */
    public function deposit(User $actor, int $amountCents, string $description, string $key): array
    {
        return DB::transaction(function () use ($actor, $amountCents, $description, $key): array {
            $this->lockUsers([$actor->getKey()]);

            $expected = [
                'type' => 'deposit',
                'from_user_id' => null,
                'to_user_id' => (int) $actor->getKey(),
                'amount_cents' => $amountCents,
                'description' => $description,
            ];
            $replay = $this->replay($actor, $key, $expected);
            if ($replay) {
                return $replay;
            }

            $operation = WalletOperation::create([
                'created_by_user_id' => $actor->getKey(),
                'from_user_id' => null,
                'to_user_id' => $actor->getKey(),
                'type' => 'deposit',
                'amount_cents' => $amountCents,
                'description' => $description,
                'occurred_on' => now()->toDateString(),
                'idempotency_key' => $key,
            ]);
            $operation->ledgerEntries()->create([
                'user_id' => $actor->getKey(),
                'amount_cents' => $amountCents,
            ]);

            return ['operation' => $operation, 'created' => true];
        }, 5);
    }

    /**
     * @return array{operation: WalletOperation, created: bool}
     */
    public function transfer(User $actor, User $recipient, int $amountCents, string $description, string $key): array
    {
        if ($actor->is($recipient)) {
            throw new WalletOperationConflictException('Não é possível transferir para a própria conta.');
        }

        return DB::transaction(function () use ($actor, $recipient, $amountCents, $description, $key): array {
            $this->lockUsers([$actor->getKey(), $recipient->getKey()]);

            $expected = [
                'type' => 'transfer',
                'from_user_id' => (int) $actor->getKey(),
                'to_user_id' => (int) $recipient->getKey(),
                'amount_cents' => $amountCents,
                'description' => $description,
            ];
            $replay = $this->replay($actor, $key, $expected);
            if ($replay) {
                return $replay;
            }

            if ($this->balanceInCents((int) $actor->getKey()) < $amountCents) {
                throw new InsufficientBalanceException('Saldo insuficiente para realizar a transferência.');
            }

            $operation = WalletOperation::create([
                'created_by_user_id' => $actor->getKey(),
                'from_user_id' => $actor->getKey(),
                'to_user_id' => $recipient->getKey(),
                'type' => 'transfer',
                'amount_cents' => $amountCents,
                'description' => $description,
                'occurred_on' => now()->toDateString(),
                'idempotency_key' => $key,
            ]);
            $operation->ledgerEntries()->createMany([
                ['user_id' => $actor->getKey(), 'amount_cents' => -$amountCents],
                ['user_id' => $recipient->getKey(), 'amount_cents' => $amountCents],
            ]);

            return ['operation' => $operation, 'created' => true];
        }, 5);
    }

    /**
     * @return array{operation: WalletOperation, created: bool}
     */
    public function reverse(User $actor, int $operationId, string $reason, string $key): array
    {
        return DB::transaction(function () use ($actor, $operationId, $reason, $key): array {
            $replay = WalletOperation::query()
                ->where('created_by_user_id', $actor->getKey())
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($replay) {
                if ((int) $replay->reverses_operation_id !== $operationId || $replay->description !== $reason) {
                    throw new WalletOperationConflictException('A chave de envio já foi usada com outra operação.');
                }

                return ['operation' => $replay, 'created' => false];
            }

            $original = WalletOperation::query()->findOrFail($operationId);
            if (! in_array($original->type, ['deposit', 'transfer'], true)) {
                throw new WalletOperationConflictException('Esta operação não pode ser revertida.');
            }

            $participants = array_values(array_filter([
                $original->from_user_id === null ? null : (int) $original->from_user_id,
                $original->to_user_id === null ? null : (int) $original->to_user_id,
            ]));
            if (! in_array((int) $actor->getKey(), $participants, true)) {
                throw (new ModelNotFoundException)->setModel(WalletOperation::class, [$operationId]);
            }

            // As contas são travadas em ordem de ID para evitar deadlocks entre transferências cruzadas.
            $this->lockUsers($participants);
            $original = WalletOperation::query()
                ->with('ledgerEntries')
                ->lockForUpdate()
                ->findOrFail($operationId);

            if (WalletOperation::query()->where('reverses_operation_id', $original->id)->exists()) {
                throw new WalletOperationConflictException('Esta operação já foi revertida.');
            }

            $reversal = WalletOperation::create([
                'created_by_user_id' => $actor->getKey(),
                'from_user_id' => $original->to_user_id,
                'to_user_id' => $original->from_user_id,
                'type' => 'reversal',
                'amount_cents' => $original->amount_cents,
                'description' => $reason,
                'occurred_on' => now()->toDateString(),
                'reverses_operation_id' => $original->id,
                'idempotency_key' => $key,
            ]);

            foreach ($original->ledgerEntries as $entry) {
                $reversal->ledgerEntries()->create([
                    'user_id' => $entry->user_id,
                    'amount_cents' => -$entry->amount_cents,
                ]);
            }

            return ['operation' => $reversal, 'created' => true];
        }, 5);
    }

    public function balanceInCents(int $userId): int
    {
        return (int) DB::table('wallet_ledger_entries')->where('user_id', $userId)->sum('amount_cents');
    }

    private function lockUsers(array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        sort($userIds, SORT_NUMERIC);

        $lockedIds = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($lockedIds) !== count($userIds)) {
            throw (new ModelNotFoundException)->setModel(User::class, $userIds);
        }
    }

    /**
     * @param  array<string, int|string|null>  $expected
     * @return array{operation: WalletOperation, created: bool}|null
     */
    private function replay(User $actor, string $key, array $expected): ?array
    {
        $operation = WalletOperation::query()
            ->where('created_by_user_id', $actor->getKey())
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if (! $operation) {
            return null;
        }

        foreach ($expected as $field => $value) {
            $stored = $operation->{$field};
            if ($value === null ? $stored !== null : (string) $stored !== (string) $value) {
                throw new WalletOperationConflictException('A chave de envio já foi usada com outra operação.');
            }
        }

        return ['operation' => $operation, 'created' => false];
    }
}

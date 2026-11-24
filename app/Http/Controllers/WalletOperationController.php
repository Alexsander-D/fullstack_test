<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\WalletOperation;
use App\Services\InsufficientBalanceException;
use App\Services\WalletOperationConflictException;
use App\Services\WalletOperationService;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WalletOperationController extends Controller
{
    public function __construct(private WalletOperationService $wallet) {}

    public function index(Request $request): JsonResponse
    {
        $history = $request->user()->ledgerEntries()
            ->with([
                'operation.sender:id,name',
                'operation.recipient:id,name',
                'operation.reversal:id,reverses_operation_id',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (WalletLedgerEntry $entry): array => $this->historyData($entry, $request->user()))
            ->values();

        return response()->json(['data' => $history]);
    }

    public function deposit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->wallet->deposit(
                $request->user(),
                Money::toCents((string) $data['amount']),
                $this->description($data['description'] ?? null, 'Depósito'),
                $this->idempotencyKey($request),
            );

            return response()->json(['data' => $this->operationData($result['operation'])], $result['created'] ? 201 : 200);
        } catch (WalletOperationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_email' => ['required', 'email', 'max:255', 'exists:users,email'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $recipient = User::query()->where('email', $data['recipient_email'])->firstOrFail();
            $result = $this->wallet->transfer(
                $request->user(),
                $recipient,
                Money::toCents((string) $data['amount']),
                $this->description($data['description'] ?? null, 'Transferência'),
                $this->idempotencyKey($request),
            );

            return response()->json(['data' => $this->operationData($result['operation'])], $result['created'] ? 201 : 200);
        } catch (WalletOperationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (InsufficientBalanceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function reverse(Request $request, int $operation): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->wallet->reverse(
                $request->user(),
                $operation,
                trim($data['reason']),
                $this->idempotencyKey($request),
            );

            return response()->json(['data' => $this->operationData($result['operation'])], $result['created'] ? 201 : 200);
        } catch (WalletOperationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'A operação não foi encontrada para esta conta.'], 404);
        }
    }

    private function historyData(WalletLedgerEntry $entry, User $viewer): array
    {
        $operation = $entry->operation;
        $viewerId = (int) $viewer->getKey();
        $counterparty = null;

        if ($operation->type === 'transfer') {
            $counterparty = $viewerId === (int) $operation->from_user_id
                ? $operation->recipient?->name
                : $operation->sender?->name;
        }

        $canReverse = in_array($operation->type, ['deposit', 'transfer'], true)
            && $operation->reversal === null;

        return [
            'id' => $operation->id,
            'type' => $operation->type,
            'description' => $operation->description,
            'amount' => Money::fromCents((int) $operation->amount_cents),
            'delta' => Money::fromCents((int) $entry->amount_cents),
            'occurred_on' => $operation->occurred_on->format('Y-m-d'),
            'counterparty' => $counterparty,
            'reversed' => $operation->reversal !== null,
            'can_reverse' => $canReverse,
            'reverses_operation_id' => $operation->reverses_operation_id,
        ];
    }

    private function operationData(WalletOperation $operation): array
    {
        return [
            'id' => $operation->id,
            'type' => $operation->type,
            'amount' => Money::fromCents((int) $operation->amount_cents),
            'description' => $operation->description,
            'occurred_on' => $operation->occurred_on->format('Y-m-d'),
            'reverses_operation_id' => $operation->reverses_operation_id,
        ];
    }

    private function idempotencyKey(Request $request): string
    {
        return strtolower(Validator::make(
            ['idempotency_key' => $request->header('Idempotency-Key')],
            ['idempotency_key' => ['required', 'uuid']],
        )->validate()['idempotency_key']);
    }

    private function description(?string $description, string $fallback): string
    {
        $description = trim((string) $description);

        return $description !== '' ? $description : $fallback;
    }
}

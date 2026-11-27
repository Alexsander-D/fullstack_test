<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_increases_balance_and_retries_do_not_duplicate_it(): void
    {
        $user = User::factory()->create();
        $this->actingAsApi($user);
        $key = (string) Str::uuid();
        $data = ['amount' => '125.50', 'description' => 'Dinheiro depositado'];

        $first = $this->postJson('/api/wallet/deposits', $data, ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJsonPath('data.amount', '125.50');
        $retry = $this->postJson('/api/wallet/deposits', $data, ['Idempotency-Key' => $key])->assertOk();

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertDatabaseCount('wallet_operations', 1);
        $this->getJson('/api/wallet/summary')->assertOk()->assertJsonPath('balance', '125.50');
    }

    public function test_deposit_adds_to_a_negative_balance_instead_of_replacing_it(): void
    {
        $user = User::factory()->create();
        $operation = WalletOperation::create([
            'created_by_user_id' => $user->id,
            'from_user_id' => $user->id,
            'type' => 'expense',
            'amount_cents' => 5000,
            'description' => 'Saldo devedor anterior',
            'occurred_on' => '2026-11-20',
        ]);
        $operation->ledgerEntries()->create(['user_id' => $user->id, 'amount_cents' => -5000]);
        $this->actingAsApi($user);

        $this->postJson('/api/wallet/deposits', ['amount' => '25.00'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated();

        $this->getJson('/api/wallet/summary')->assertJsonPath('balance', '-25.00');
    }

    public function test_transfer_checks_balance_and_credits_the_recipient(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $this->actingAsApi($sender);
        $this->postJson('/api/wallet/deposits', ['amount' => '20.00'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $transfer = $this->postJson('/api/wallet/transfers', [
            'recipient_email' => $recipient->email,
            'amount' => '12.50',
            'description' => 'Almoço',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->getJson('/api/wallet/summary')->assertJsonPath('balance', '7.50');
        $this->withToken(auth('api')->login($recipient))
            ->getJson('/api/wallet/summary')
            ->assertJsonPath('balance', '12.50');
        $this->getJson('/api/wallet/operations')
            ->assertJsonPath('data.0.type', 'transfer')
            ->assertJsonPath('data.0.delta', '12.50')
            ->assertJsonPath('data.0.counterparty', $sender->name);

        $this->withToken(auth('api')->login($sender))
            ->postJson('/api/wallet/transfers', [
                'recipient_email' => $recipient->email,
                'amount' => '7.51',
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Saldo insuficiente para realizar a transferência.');

        $this->assertDatabaseCount('wallet_operations', 2);
        $this->assertNotNull($transfer->json('data.id'));
    }

    public function test_transfer_retries_are_idempotent_and_same_key_cannot_change_the_recipient_or_amount(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $anotherRecipient = User::factory()->create();
        $this->actingAsApi($sender);
        $this->postJson('/api/wallet/deposits', ['amount' => '30.00'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $key = (string) Str::uuid();
        $data = ['recipient_email' => $recipient->email, 'amount' => '10.00'];

        $first = $this->postJson('/api/wallet/transfers', $data, ['Idempotency-Key' => $key])->assertCreated();
        $retry = $this->postJson('/api/wallet/transfers', $data, ['Idempotency-Key' => $key])->assertOk();
        $this->assertSame($first->json('data.id'), $retry->json('data.id'));

        $this->postJson('/api/wallet/transfers', [
            'recipient_email' => $anotherRecipient->email,
            'amount' => '10.00',
        ], ['Idempotency-Key' => $key])->assertStatus(409);

        $this->assertDatabaseCount('wallet_operations', 2);
    }

    public function test_reversal_is_audited_and_a_later_deposit_reduces_the_negative_balance(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $thirdUser = User::factory()->create();
        $this->actingAsApi($sender);
        $this->postJson('/api/wallet/deposits', ['amount' => '100.00'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $transfer = $this->postJson('/api/wallet/transfers', [
            'recipient_email' => $recipient->email,
            'amount' => '80.00',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $transferId = $transfer->json('data.id');

        $this->withToken(auth('api')->login($recipient))
            ->postJson('/api/wallet/transfers', [
                'recipient_email' => $thirdUser->email,
                'amount' => '60.00',
            ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->withToken(auth('api')->login($sender));
        $key = (string) Str::uuid();
        $reason = 'Transferência feita por engano';
        $reversal = $this->postJson('/api/wallet/operations/'.$transferId.'/reverse', [
            'reason' => $reason,
        ], ['Idempotency-Key' => $key])->assertCreated();
        $retry = $this->postJson('/api/wallet/operations/'.$transferId.'/reverse', [
            'reason' => $reason,
        ], ['Idempotency-Key' => $key])->assertOk();
        $this->assertSame($reversal->json('data.id'), $retry->json('data.id'));
        $this->postJson('/api/wallet/operations/'.$transferId.'/reverse', [
            'reason' => $reason,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);

        $this->withToken(auth('api')->login($recipient))
            ->getJson('/api/wallet/summary')
            ->assertJsonPath('balance', '-60.00');
        $this->postJson('/api/wallet/deposits', ['amount' => '70.00'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/wallet/summary')->assertJsonPath('balance', '10.00');
        $this->getJson('/api/wallet/operations')->assertJsonPath('data.0.type', 'deposit');

        $this->withToken(auth('api')->login($sender))
            ->getJson('/api/wallet/operations')
            ->assertJsonPath('data.0.type', 'reversal')
            ->assertJsonPath('data.0.description', $reason);
        $this->assertDatabaseCount('wallet_operations', 5);
    }

    public function test_deposit_reversal_keeps_both_records_and_can_only_happen_once(): void
    {
        $user = User::factory()->create();
        $this->actingAsApi($user);
        $deposit = $this->postJson('/api/wallet/deposits', [
            'amount' => '15.75',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $depositId = $deposit->json('data.id');

        $this->postJson('/api/wallet/operations/'.$depositId.'/reverse', [
            'reason' => 'Depósito lançado incorretamente',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->getJson('/api/wallet/summary')->assertJsonPath('balance', '0.00');
        $this->getJson('/api/wallet/operations')
            ->assertJsonPath('data.1.type', 'deposit')
            ->assertJsonPath('data.1.reversed', true)
            ->assertJsonPath('data.0.type', 'reversal');
        $this->assertDatabaseCount('wallet_operations', 2);

        $this->postJson('/api/wallet/operations/'.$depositId.'/reverse', [
            'reason' => 'Outro motivo',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
        $this->assertDatabaseCount('wallet_operations', 2);
    }

    public function test_only_transfer_participants_can_reverse_and_transfers_cannot_target_the_sender(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $this->actingAsApi($sender);
        $this->postJson('/api/wallet/deposits', ['amount' => '10.00'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $transfer = $this->postJson('/api/wallet/transfers', [
            'recipient_email' => $recipient->email,
            'amount' => '5.00',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->postJson('/api/wallet/transfers', [
            'recipient_email' => $sender->email,
            'amount' => '1.00',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Não é possível transferir para a própria conta.');

        $this->withToken(auth('api')->login($outsider))
            ->postJson('/api/wallet/operations/'.$transfer->json('data.id').'/reverse', [
                'reason' => 'Não sou participante',
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertNotFound();
    }

    public function test_wallet_history_is_private_and_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $this->getJson('/api/wallet/operations')->assertUnauthorized();
        $this->actingAsApi($owner);
        $this->postJson('/api/wallet/deposits', ['amount' => '4.00'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $otherUser = User::factory()->create();
        $this->withToken(auth('api')->login($otherUser))
            ->getJson('/api/wallet/operations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/wallet/summary')->assertJsonPath('balance', '0.00');
    }

    public function test_operation_creation_requires_a_valid_idempotency_key_and_amount(): void
    {
        $user = User::factory()->create();
        $this->actingAsApi($user);

        $this->postJson('/api/wallet/deposits', ['amount' => '1.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/wallet/deposits', ['amount' => '-1.00'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    private function actingAsApi(User $user): void
    {
        $this->withToken(auth('api')->login($user));
    }
}

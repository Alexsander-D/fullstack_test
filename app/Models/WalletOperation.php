<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'created_by_user_id',
    'from_user_id',
    'to_user_id',
    'type',
    'amount_cents',
    'description',
    'occurred_on',
    'reverses_operation_id',
    'legacy_entry_id',
    'idempotency_key',
])]
class WalletOperation extends Model
{
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'occurred_on' => 'date:Y-m-d',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class, 'operation_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_operation_id');
    }

    public function reversedOperation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_operation_id');
    }
}

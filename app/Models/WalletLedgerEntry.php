<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['operation_id', 'user_id', 'amount_cents'])]
class WalletLedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(WalletOperation::class, 'operation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

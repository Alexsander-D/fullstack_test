<?php

namespace App\Http\Controllers;

use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletSummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $balanceCents = (int) $request->user()->ledgerEntries()->sum('amount_cents');

        return response()->json([
            'balance' => Money::fromCents($balanceCents),
        ]);
    }
}

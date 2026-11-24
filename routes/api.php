<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WalletOperationController;
use App\Http\Controllers\WalletSummaryController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/wallet/summary', [WalletSummaryController::class, 'show']);
    Route::get('/wallet/operations', [WalletOperationController::class, 'index']);
    Route::post('/wallet/deposits', [WalletOperationController::class, 'deposit']);
    Route::post('/wallet/transfers', [WalletOperationController::class, 'transfer']);
    Route::post('/wallet/operations/{operation}/reverse', [WalletOperationController::class, 'reverse']);
});

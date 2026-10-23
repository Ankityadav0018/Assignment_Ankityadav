<?php

use App\Http\Controllers\MockInsurerController;
use App\Http\Controllers\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| All routes here are automatically prefixed with /api and assigned the
| "api" middleware group (rate limiting, JSON content negotiation, etc.).
*/

Route::apiResource('policy-requests', PolicyRequestController::class)
    ->parameters(['policy-requests' => 'id'])
    ->only(['index', 'store', 'show']);

// ── Mock Insurer Simulation Endpoints ─────────────────────────────────────────
Route::prefix('mock-insurer')->group(function () {
    Route::post('issue', [MockInsurerController::class, 'issue'])->name('mock-insurer.issue');
    Route::get('status/{idempotency_key?}', [MockInsurerController::class, 'status'])->name('mock-insurer.status');
    Route::post('callback', [MockInsurerController::class, 'callback'])->name('mock-insurer.callback');
});

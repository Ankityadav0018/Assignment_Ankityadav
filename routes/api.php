<?php

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
    ->only(['index', 'store', 'show']);

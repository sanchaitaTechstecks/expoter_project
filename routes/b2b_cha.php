<?php

use App\Http\Controllers\Api\V1\Cha\ChaAuthController;
use App\Http\Controllers\Api\V1\Cha\ChaShipmentController;
use Illuminate\Support\Facades\Route;

// Public CHA Portal Auth routes
Route::post('login', [ChaAuthController::class, 'login']);

// Authenticated Customs House Agent routes
Route::middleware(['auth:sanctum', 'b2b_cha'])->group(function () {

    // 1. CHA Profile & Workload
    Route::controller(ChaAuthController::class)->group(function () {
        Route::get('profile', 'profile');
        Route::post('profile', 'updateProfile');
        Route::post('logout', 'logout');
    });

    // 2. Customs Clearance Queue, Filings, Duties, Examination & Release (Section 15)
    Route::controller(ChaShipmentController::class)->group(function () {
        Route::get('shipments', 'index');
        Route::get('shipments/{id}', 'show');
        Route::get('shipments/{id}/checklist', 'checklist');
        Route::post('shipments/{id}/filing', 'filing');
        Route::post('shipments/{id}/duty-calculation', 'dutyCalculation');
        Route::post('shipments/{id}/examination', 'examination');
        Route::post('shipments/{id}/release', 'release');
    });
});

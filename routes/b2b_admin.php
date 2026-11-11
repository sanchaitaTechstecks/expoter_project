<?php

use App\Http\Controllers\Api\V1\B2B\B2bCreditNoteController;
use App\Http\Controllers\Api\V1\B2B\B2bDashboardController;
use App\Http\Controllers\Api\V1\B2B\B2bReportController;
use App\Http\Controllers\Api\V1\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\AdminCategoryMasterController;
use App\Http\Controllers\Api\V1\Admin\AdminDisputeController;
use App\Http\Controllers\Api\V1\Admin\AdminDocumentChecklistController;
use App\Http\Controllers\Api\V1\Admin\AdminProductModerationController;
use App\Http\Controllers\Api\V1\Admin\AdminRfqController;
use App\Http\Controllers\Api\V1\Admin\AdminSupplierController;
use App\Http\Controllers\Api\V1\Admin\AdminUnitConversionController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

// Public Admin Auth routes
Route::post('login', [AdminAuthController::class, 'login']);

// Protected Admin Panel & Moderation routes (Sanctum + Admin privilege check)
Route::middleware(['auth:sanctum', 'b2b_admin'])->group(function () {

    // 1. Admin Profile & Session
    Route::controller(AdminAuthController::class)->group(function () {
        Route::get('profile', 'profile');
        Route::post('profile', 'updateProfile');
        Route::post('logout', 'logout');
    });

    // P2-9: dashboard & reports
    Route::get('dashboard', [B2bDashboardController::class, 'show'])->defaults('portal', 'admin');
    Route::get('dashboard/summary', [B2bDashboardController::class, 'summary'])->defaults('portal', 'admin');
    Route::get('reports', [B2bReportController::class, 'index'])->defaults('portal', 'admin');
    Route::get('reports/{report}', [B2bReportController::class, 'show'])->defaults('portal', 'admin');
    Route::get('reports/{report}/export', [B2bReportController::class, 'export'])->defaults('portal', 'admin');

    // P2-2: RFQ queue & assignment
    Route::controller(AdminRfqController::class)->prefix('rfqs')->group(function () {
        Route::get('/', 'index');
        Route::get('stats', 'stats');
        Route::get('{id}', 'show');
        Route::post('{id}/assign', 'assign');
        Route::post('{id}/unassign', 'unassign');
    });

    // P2-6: document checklist templates
    Route::controller(AdminDocumentChecklistController::class)->prefix('document-checklist-templates')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('{id}', 'show');
        Route::post('{id}', 'update');
        Route::put('{id}', 'update');
        Route::delete('{id}', 'destroy');
    });

    // P2-4: unit conversion master data
    Route::controller(AdminUnitConversionController::class)->prefix('unit-conversions')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::post('seed-defaults', 'seedDefaults');
        Route::post('{id}', 'update');
        Route::delete('{id}', 'destroy');
    });

    // P2-7: credit notes (read + administration)
    Route::controller(B2bCreditNoteController::class)->prefix('credit-notes')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('{id}', 'show');
        Route::post('{id}/issue', 'issue');
        Route::post('{id}/apply', 'apply');
        Route::post('{id}/void', 'void');
    });

    // 2. Staff & User Account Management
    Route::controller(AdminUserController::class)->group(function () {
        Route::get('users', 'index');
        Route::post('users', 'store');
        Route::post('users/{id}/status', 'updateStatus');
    });

    // 3. Supplier Verification Approvals (Section 3 & 19)
    Route::controller(AdminSupplierController::class)->group(function () {
        Route::get('suppliers', 'index');
        Route::get('suppliers/{id}', 'show');
        Route::post('suppliers/{id}/verify', 'verify');
    });

    // 4. Product Publication & Lot Pricing Approvals
    Route::controller(AdminProductModerationController::class)->group(function () {
        Route::get('products', 'products');
        Route::post('products/{id}/approval', 'approveProduct');
        Route::get('lots/pending-approval', 'pendingLots');
        Route::post('lots/{id}/approval', 'approveLotPrice');
    });

    // 5. Category & HS Code Masters
    Route::controller(AdminCategoryMasterController::class)->group(function () {
        Route::get('categories', 'index');
        Route::post('categories', 'store');
        Route::post('categories/{id}', 'update');
        Route::put('categories/{id}', 'update');
        Route::delete('categories/{id}', 'destroy');
        Route::get('hs-codes', 'hsCodesIndex');
        Route::post('hs-codes', 'hsCodesStore');
    });

    // 6. Order Dispute Resolution & Arbitration
    Route::controller(AdminDisputeController::class)->group(function () {
        Route::get('disputes', 'index');
        Route::get('disputes/{id}', 'show');
        Route::post('disputes/{id}/resolve', 'resolve');
    });

    // 7. System Audit Logs & Compliance Trails (SRS Section 19)
    Route::controller(AdminAuditLogController::class)->group(function () {
        Route::get('audit-logs', 'index');
        Route::get('audit-logs/{id}', 'show');
    });
});

<?php

namespace App\Http\Controllers\Api\V1\B2B;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Services\B2B\B2bReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P2-9: role-scoped dashboard summary.
 */
class B2bDashboardController extends B2bBaseController
{
    public function __construct(protected B2bReportService $reports)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $portal = $request->route('portal') ?: $this->inferPortal($user);

        if ($portal === 'admin' && !in_array($user->user_type, ['admin', 'staff'], true)) {
            return $this->errorResponse('Admin dashboard requires back-office access', 403);
        }

        return $this->successResponse($this->reports->dashboard($portal, $user), 'Dashboard summary retrieved successfully');
    }

    /**
     * Lightweight counters polling endpoint (same payload, narrower cache window).
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $portal = $request->route('portal') ?: $this->inferPortal($user);

        if ($portal === 'admin' && !in_array($user->user_type, ['admin', 'staff'], true)) {
            return $this->errorResponse('Admin dashboard requires back-office access', 403);
        }

        $data = $this->reports->dashboard($portal, $user);

        return $this->successResponse([
            'portal' => $data['portal'],
            'orders' => $data['orders'] ?? null,
            'payments' => $data['payments'] ?? null,
            'disputes' => $data['disputes'] ?? null,
            'shipments' => $data['shipments'] ?? null,
        ], 'Dashboard counters retrieved successfully');
    }

    private function inferPortal($user): string
    {
        return match ($user->user_type) {
            'seller' => 'exporter',
            'admin', 'staff' => 'admin',
            default => 'importer',
        };
    }
}

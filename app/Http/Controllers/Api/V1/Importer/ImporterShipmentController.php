<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bShipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImporterShipmentController extends B2bBaseController
{
    /**
     * List shipments for buyer orders.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bShipment::with(['order', 'supplier.company', 'containers'])
            ->where('buyer_id', $user->id);

        if ($request->has('order_id') && !empty($request->order_id)) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        $shipments = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($shipments, 'Shipments retrieved successfully');
    }

    /**
     * View shipment details with containers.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $shipment = B2bShipment::with([
            'order.items',
            'supplier.company',
            'containers',
            'trackingEvents' => function ($q) {
                $q->orderBy('actual_at', 'asc')->orderBy('id', 'asc');
            },
            'documents',
        ])
        ->where('buyer_id', $user->id)
        ->find($id);

        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        return $this->successResponse($shipment, 'Shipment details retrieved successfully');
    }

    /**
     * Real-time tracking events and milestone timeline (BR-009).
     */
    public function track($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $shipment = B2bShipment::with([
            'trackingEvents' => function ($q) {
                $q->orderBy('actual_at', 'asc')->orderBy('id', 'asc');
            },
            'containers',
        ])
        ->where('buyer_id', $user->id)
        ->find($id);

        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        return $this->successResponse([
            'shipment_number' => $shipment->shipment_number,
            'status' => $shipment->status,
            'mode' => $shipment->mode,
            'carrier_name' => $shipment->carrier_name,
            'vessel_name' => $shipment->vessel_name,
            'voyage_number' => $shipment->voyage_number,
            'tracking_number' => $shipment->tracking_number,
            'origin_port' => $shipment->origin_port,
            'destination_port' => $shipment->destination_port,
            'departure_date' => $shipment->departure_date,
            'estimated_arrival' => $shipment->estimated_arrival,
            'actual_arrival' => $shipment->actual_arrival,
            'containers' => $shipment->containers,
            'timeline' => $shipment->trackingEvents,
        ], 'Shipment tracking timeline retrieved successfully');
    }
}

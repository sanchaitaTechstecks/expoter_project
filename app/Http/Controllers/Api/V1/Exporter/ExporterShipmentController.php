<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bContainer;
use App\Models\B2B\B2bContainerAssignment;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bShipment;
use App\Models\B2B\B2bShipmentEtaRevision;
use App\Models\B2B\B2bShipmentVehicle;
use App\Models\B2B\B2bTrackingEvent;
use App\Services\B2B\B2bTradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExporterShipmentController extends B2bBaseController
{
    protected B2bTradeService $tradeService;

    public function __construct(B2bTradeService $tradeService)
    {
        $this->tradeService = $tradeService;
    }

    /**
     * Create shipment for an order (Section 12.1).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:b2b_orders,id',
            'mode' => 'required|in:sea,air,road,rail,multimodal',
            'carrier_name' => 'nullable|string|max:191',
            'freight_forwarder' => 'nullable|string|max:191',
            'booking_reference' => 'nullable|string|max:191',
            'tracking_number' => 'nullable|string|max:191',
            'vessel_name' => 'nullable|string|max:191',
            'voyage_number' => 'nullable|string|max:100',
            'origin_port' => 'nullable|string|max:191',
            'origin_address' => 'nullable|string',
            'destination_port' => 'nullable|string|max:191',
            'final_destination' => 'nullable|string',
            'departure_date' => 'nullable|date',
            'estimated_arrival' => 'nullable|date',
            'assigned_cha' => 'nullable|string|max:191',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $order = B2bOrder::where('id', $request->order_id)->where('supplier_id', $user->id)->first();
        if (!$order) {
            return $this->errorResponse('Order not found or access denied', 404);
        }

        $shipmentNumber = 'SHP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $shipment = B2bShipment::create([
            'shipment_number' => $shipmentNumber,
            'order_id' => $order->id,
            'supplier_id' => $user->id,
            'buyer_id' => $order->buyer_id,
            'mode' => $request->mode,
            'carrier_name' => $request->carrier_name,
            'freight_forwarder' => $request->freight_forwarder,
            'booking_reference' => $request->booking_reference,
            'tracking_number' => $request->tracking_number,
            'vessel_name' => $request->vessel_name,
            'voyage_number' => $request->voyage_number,
            'origin_port' => $request->origin_port,
            'origin_address' => $request->origin_address,
            'destination_port' => $request->destination_port,
            'final_destination' => $request->final_destination,
            'departure_date' => $request->departure_date,
            'estimated_arrival' => $request->estimated_arrival,
            'status' => 'planned',
            'assigned_cha' => $request->assigned_cha,
            'notes' => $request->notes,
        ]);

        // Create initial tracking event
        B2bTrackingEvent::create([
            'shipment_id' => $shipment->id,
            'event_type' => 'goods_ready',
            'location' => $request->origin_port ?: 'Origin Warehouse',
            'event_status' => 'completed',
            'actual_at' => now(),
            'source' => 'manual',
            'description' => 'Shipment planned and booking reference recorded',
            'created_by' => $user->id,
        ]);

        return $this->successResponse($shipment->load('containers', 'trackingEvents'), 'Shipment created successfully', 201);
    }

    /**
     * List exporter shipments.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bShipment::with(['order', 'buyer.company', 'containers'])
            ->where('supplier_id', $user->id);

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
     * View shipment details.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $shipment = B2bShipment::with([
            'order.items',
            'buyer.company',
            'containers',
            'trackingEvents' => function ($q) {
                $q->orderBy('actual_at', 'asc')->orderBy('id', 'asc');
            },
            'documents',
        ])
        ->where('supplier_id', $user->id)
        ->find($id);

        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        return $this->successResponse($shipment, 'Shipment details retrieved successfully');
    }

    /**
     * Update shipment details and status.
     */
    public function update($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $shipment = B2bShipment::where('supplier_id', $user->id)->find($id);

        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string|max:50',
            'carrier_name' => 'nullable|string|max:191',
            'tracking_number' => 'nullable|string|max:191',
            'vessel_name' => 'nullable|string|max:191',
            'voyage_number' => 'nullable|string|max:100',
            'departure_date' => 'nullable|date',
            'estimated_arrival' => 'nullable|date',
            'actual_arrival' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $shipment->update($request->only([
            'status',
            'carrier_name',
            'tracking_number',
            'vessel_name',
            'voyage_number',
            'departure_date',
            'estimated_arrival',
            'actual_arrival',
        ]));

        return $this->successResponse($shipment, 'Shipment updated successfully');
    }

    /**
     * Add container to shipment (Section 12.2).
     */
    public function addContainer($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $shipment = B2bShipment::where('supplier_id', $user->id)->find($id);

        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'container_number' => 'required|string|max:50',
            'container_type' => 'required|in:20FT,40FT,40FT_HC,Reefer,Flat_Rack,Open_Top',
            'seal_number' => 'nullable|string|max:50',
            'loaded_quantity' => 'required|numeric|min:0.001',
            'unit' => 'required|string|max:20',
            'gross_weight' => 'nullable|numeric|min:0',
            'net_weight' => 'nullable|numeric|min:0',
            'current_location' => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $container = B2bContainer::create([
            'shipment_id' => $shipment->id,
            'container_number' => $request->container_number,
            'container_type' => $request->container_type,
            'seal_number' => $request->seal_number,
            'loaded_quantity' => $request->loaded_quantity,
            'unit' => $request->unit,
            'gross_weight' => $request->gross_weight,
            'net_weight' => $request->net_weight,
            'current_location' => $request->current_location ?: $shipment->origin_port,
            'status' => 'assigned',
        ]);

        B2bContainerAssignment::create([
            'container_id' => $container->id,
            'shipment_id' => $shipment->id,
            'action' => 'assigned',
            'actor_id' => $user->id,
            'notes' => 'Container assigned to shipment ' . $shipment->shipment_number,
        ]);

        return $this->successResponse($container, 'Container added to shipment', 201);
    }

    /**
     * Add tracking event / milestone (Section 12.4 & 12.5).
     */
    public function addEvent($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $shipment = B2bShipment::with('order.items')->where('supplier_id', $user->id)->find($id);

        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'event_type' => 'required|string|max:100', // order_received, goods_ready, at_origin_port, customs_processing, loaded_onto_vessel, vessel_departed, in_transit, destination_port_reached, customs_cleared, delivered
            'location' => 'required|string|max:191',
            'event_status' => 'required|in:completed,in_progress,scheduled,delayed',
            'actual_at' => 'nullable|date',
            'planned_at' => 'nullable|date',
            'timezone' => 'nullable|timezone',
            'source' => 'nullable|in:manual,carrier_api,gps',
            'description' => 'nullable|string',
            'evidence_attachment' => 'nullable|string',
            'container_id' => 'nullable|exists:b2b_containers,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        // P2-5: operational timezone is captured alongside the UTC timestamp so
        // downstream reports never have to guess the origin timezone.
        $timezone = $request->timezone ?: config('app.timezone', 'UTC');
        $actualAt = $request->actual_at ? \Carbon\Carbon::parse($request->actual_at, $timezone) : now();

        $event = B2bTrackingEvent::create([
            'shipment_id' => $shipment->id,
            'container_id' => $request->container_id,
            'event_type' => $request->event_type,
            'location' => $request->location,
            'event_status' => $request->event_status,
            'planned_at' => $request->planned_at,
            'actual_at' => $actualAt,
            'timezone' => $timezone,
            'event_time_local' => $actualAt->copy()->setTimezone($timezone),
            'actual_at_utc' => $actualAt->copy()->setTimezone('UTC'),
            'source' => $request->source ?: 'manual', // BR-009 & BR-020: clearly labelled
            'description' => $request->description,
            'evidence_attachment' => $request->evidence_attachment,
            'created_by' => $user->id,
        ]);

        // Map milestone to shipment status
        $statusMapping = [
            'goods_ready' => 'ready',
            'at_origin_port' => 'at_origin_port',
            'customs_processing' => 'customs_processing',
            'loaded_onto_vessel' => 'loaded',
            'vessel_departed' => 'departed',
            'in_transit' => 'in_transit',
            'destination_port_reached' => 'at_destination_port',
            'customs_cleared' => 'cleared',
            'out_for_delivery' => 'out_for_delivery',
            'delivered' => 'delivered',
        ];

        if (isset($statusMapping[$request->event_type])) {
            $shipment->status = $statusMapping[$request->event_type];
            $shipment->save();
        }

        // If departure milestone, record dispatch in lot inventory (Section 5)
        if (in_array($request->event_type, ['loaded_onto_vessel', 'vessel_departed'])) {
            foreach ($shipment->order->items as $item) {
                if ($item->lot_id && $item->outstanding_quantity > 0) {
                    try {
                        $this->tradeService->dispatchLotStock(
                            $item->lot_id,
                            (float)$item->quantity,
                            'shipment',
                            $shipment->id,
                            $user->id,
                            "Dispatched via shipment #{$shipment->shipment_number}"
                        );
                        $item->dispatched_quantity = $item->quantity;
                        $item->outstanding_quantity = 0;
                        $item->save();
                    } catch (\Exception $e) {
                        // Stock already dispatched or log notice
                    }
                }
            }
        }

        return $this->successResponse($event, 'Tracking milestone recorded successfully', 201);
    }

    /**
     * P2-5: Revise the ETA and keep the full revision trail.
     */
    public function reviseEta($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $shipment = B2bShipment::where('supplier_id', $user->id)->find($id);

        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'new_eta' => 'required|date',
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        if (in_array($shipment->status, ['delivered', 'cancelled'], true)) {
            return $this->errorResponse("ETA cannot be revised for a {$shipment->status} shipment", 400);
        }

        $previous = $shipment->estimated_arrival ? $shipment->estimated_arrival->toDateString() : null;

        $revision = DB::transaction(function () use ($shipment, $request, $user, $previous) {
            $revision = B2bShipmentEtaRevision::create([
                'shipment_id' => $shipment->id,
                'previous_eta' => $previous,
                'new_eta' => $request->new_eta,
                'reason' => $request->reason,
                'revised_by' => $user->id,
            ]);

            $shipment->estimated_arrival = $request->new_eta;
            $shipment->save();

            B2bTrackingEvent::create([
                'shipment_id' => $shipment->id,
                'event_type' => 'eta_revised',
                'location' => $shipment->destination_port ?: 'Destination',
                'event_status' => 'scheduled',
                'planned_at' => $request->new_eta,
                'timezone' => config('app.timezone', 'UTC'),
                'event_time_local' => now(),
                'actual_at_utc' => now()->setTimezone('UTC'),
                'source' => 'manual',
                'description' => 'ETA revised from ' . ($previous ?: 'not set') . ' to ' . $request->new_eta . '. Reason: ' . $request->reason,
                'created_by' => $user->id,
            ]);

            return $revision;
        });

        return $this->successResponse($revision->load('reviser:id,name'), 'Shipment ETA revised successfully', 201);
    }

    public function etaRevisions($id, Request $request): JsonResponse
    {
        $shipment = B2bShipment::where('supplier_id', $request->user()->id)->find($id);
        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        $revisions = B2bShipmentEtaRevision::with('reviser:id,name')
            ->where('shipment_id', $shipment->id)
            ->orderByDesc('id')
            ->get();

        return $this->successResponse([
            'shipment_id' => $shipment->id,
            'current_eta' => $shipment->estimated_arrival ? $shipment->estimated_arrival->toDateString() : null,
            'revision_count' => $revisions->count(),
            'revisions' => $revisions,
        ], 'Shipment ETA revisions retrieved successfully');
    }

    /**
     * P2-5: Assign a vehicle / driver to a shipment.
     */
    public function assignVehicle($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $shipment = B2bShipment::where('supplier_id', $user->id)->find($id);

        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'vehicle_type' => 'nullable|in:truck,trailer,van,rail_wagon,other',
            'vehicle_number' => 'required|string|max:50',
            'driver_name' => 'nullable|string|max:191',
            'driver_phone' => 'nullable|string|max:30',
            'driver_license' => 'nullable|string|max:100',
            'capacity' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $vehicle = B2bShipmentVehicle::create([
            'shipment_id' => $shipment->id,
            'vehicle_type' => $request->vehicle_type,
            'vehicle_number' => $request->vehicle_number,
            'driver_name' => $request->driver_name,
            'driver_phone' => $request->driver_phone,
            'driver_license' => $request->driver_license,
            'capacity' => $request->capacity,
            'unit' => $request->unit ?: 'kg',
            'status' => 'assigned',
            'assigned_at' => now(),
            'actor_id' => $user->id,
        ]);

        return $this->successResponse($vehicle, 'Vehicle assigned to shipment successfully', 201);
    }

    public function vehicles($id, Request $request): JsonResponse
    {
        $shipment = B2bShipment::where('supplier_id', $request->user()->id)->find($id);
        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        return $this->successResponse(
            B2bShipmentVehicle::where('shipment_id', $shipment->id)->orderByDesc('id')->get(),
            'Shipment vehicles retrieved successfully'
        );
    }

    public function releaseVehicle($id, $vehicleId, Request $request): JsonResponse
    {
        $shipment = B2bShipment::where('supplier_id', $request->user()->id)->find($id);
        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        $vehicle = B2bShipmentVehicle::where('shipment_id', $shipment->id)->find($vehicleId);
        if (!$vehicle) {
            return $this->errorResponse('Vehicle assignment not found', 404);
        }

        if ($vehicle->status === 'released') {
            return $this->errorResponse('Vehicle has already been released', 409);
        }

        $vehicle->status = 'released';
        $vehicle->released_at = now();
        $vehicle->actor_id = $request->user()->id;
        $vehicle->save();

        return $this->successResponse($vehicle, 'Vehicle released successfully');
    }

    /**
     * P2-5: Container assignment history for a shipment.
     */
    public function containerHistory($id, Request $request): JsonResponse
    {
        $shipment = B2bShipment::where('supplier_id', $request->user()->id)->find($id);
        if (!$shipment) {
            return $this->errorResponse('Shipment not found or access denied', 404);
        }

        $history = B2bContainerAssignment::with('container:id,container_number,container_type,status')
            ->where('shipment_id', $shipment->id)
            ->orderByDesc('id')
            ->get();

        return $this->successResponse($history, 'Container assignment history retrieved successfully');
    }
}

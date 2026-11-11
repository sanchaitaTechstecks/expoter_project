<?php

namespace App\Services\B2B;

use App\Models\B2B\B2bCreditNote;
use App\Models\B2B\B2bDispute;
use App\Models\B2B\B2bInvoice;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bPayment;
use App\Models\B2B\B2bShipment;
use App\Models\B2B\B2bTradeDocument;
use App\Models\B2B\ProductLot;
use App\Models\B2B\Rfq;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P2-9: role-scoped dashboards and the 14 platform reports.
 */
class B2bReportService
{
    public const REPORTS = [
        'sales_summary' => 'Sales Summary',
        'purchase_summary' => 'Purchase Summary',
        'order_pipeline' => 'Order Pipeline',
        'rfq_performance' => 'RFQ Performance',
        'inventory_valuation' => 'Inventory Valuation',
        'low_stock' => 'Low Stock',
        'inventory_ageing' => 'Inventory Ageing',
        'shipment_performance' => 'Shipment Performance',
        'shipment_delays' => 'Shipment Delays',
        'document_compliance' => 'Document Compliance',
        'payment_reconciliation' => 'Payment Reconciliation',
        'dispute_summary' => 'Dispute Summary',
        'supplier_performance' => 'Supplier Performance',
        'top_products' => 'Top Products',
    ];

    public function dashboard(string $portal, User $user): array
    {
        return match ($portal) {
            'exporter' => $this->exporterDashboard($user),
            'admin' => $this->adminDashboard($user),
            default => $this->importerDashboard($user),
        };
    }

    private function importerDashboard(User $user): array
    {
        $orders = B2bOrder::where('buyer_id', $user->id);
        $payments = B2bPayment::where('buyer_id', $user->id);

        return [
            'portal' => 'importer',
            'orders' => [
                'total' => (clone $orders)->count(),
                'open' => (clone $orders)->whereNotIn('status', ['delivered', 'cancelled', 'closed'])->count(),
                'delivered' => (clone $orders)->where('status', 'delivered')->count(),
                'total_value' => round((float) (clone $orders)->sum('total_amount'), 2),
                'amount_paid' => round((float) (clone $orders)->sum('amount_paid'), 2),
                'balance_due' => round((float) (clone $orders)->sum('balance_due'), 2),
            ],
            'rfqs' => [
                'open' => Rfq::where('buyer_id', $user->id)->whereIn('status', ['submitted', 'under_review', 'supplier_responding'])->count(),
                'total' => Rfq::where('buyer_id', $user->id)->count(),
                'quotations_received' => \App\Models\B2B\B2bQuotation::where('buyer_id', $user->id)->count(),
            ],
            'payments' => [
                'pending_verification' => (clone $payments)->where('status', 'verification_required')->count(),
                'verified_total' => round((float) (clone $payments)->where('status', 'paid')->sum('amount'), 2),
            ],
            'disputes' => [
                'open' => B2bDispute::where(function ($q) use ($user) {
                    $q->where('raised_by_id', $user->id)->orWhereHas('order', function ($o) use ($user) {
                        $o->where('buyer_id', $user->id);
                    });
                })->whereIn('status', ['opened', 'under_investigation', 'arbitration', 'escalated'])->count(),
            ],
            'shipments' => [
                'in_transit' => B2bShipment::where('buyer_id', $user->id)
                    ->whereNotIn('status', ['delivered', 'cancelled', 'planned'])->count(),
                'arriving_30d' => B2bShipment::where('buyer_id', $user->id)
                    ->whereNotNull('estimated_arrival')
                    ->whereBetween('estimated_arrival', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count(),
            ],
            'documents' => [
                'total' => B2bTradeDocument::whereHas('order', function ($q) use ($user) {
                    $q->where('buyer_id', $user->id);
                })->count(),
                'expiring_30d' => B2bTradeDocument::whereHas('order', function ($q) use ($user) {
                    $q->where('buyer_id', $user->id);
                })->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count(),
            ],
            'recent_orders' => B2bOrder::where('buyer_id', $user->id)
                ->select(['id', 'order_number', 'status', 'total_amount', 'currency', 'created_at'])
                ->orderByDesc('id')->limit(5)->get(),
        ];
    }

    private function exporterDashboard(User $user): array
    {
        $orders = B2bOrder::where('supplier_id', $user->id);
        $payments = B2bPayment::where('supplier_id', $user->id);
        $lots = ProductLot::where('supplier_id', $user->id);

        return [
            'portal' => 'exporter',
            'catalogue' => [
                'products' => Product::where('user_id', $user->id)->count(),
                'published' => Product::where('user_id', $user->id)->where('published', 1)->count(),
                'pending_approval' => Product::where('user_id', $user->id)->where('publication_status', 'pending_approval')->count(),
                'archived' => Product::where('user_id', $user->id)->where('publication_status', 'archived')->count(),
            ],
            'inventory' => [
                'lots' => (clone $lots)->count(),
                'available_quantity' => round((float) (clone $lots)->sum('quantity_available'), 3),
                'stock_value' => round((float) (clone $lots)->sum(DB::raw('quantity_available * unit_price')), 2),
                'low_stock' => (clone $lots)->where('low_stock_threshold', '>', 0)
                    ->whereColumn('quantity_available', '<=', 'low_stock_threshold')->count(),
                'expiring_30d' => (clone $lots)->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count(),
            ],
            'orders' => [
                'total' => (clone $orders)->count(),
                'open' => (clone $orders)->whereNotIn('status', ['delivered', 'cancelled', 'closed'])->count(),
                'revenue' => round((float) (clone $orders)->sum('total_amount'), 2),
                'received' => round((float) (clone $orders)->sum('amount_paid'), 2),
                'outstanding' => round((float) (clone $orders)->sum('balance_due'), 2),
            ],
            'payments' => [
                'awaiting_verification' => (clone $payments)->where('status', 'verification_required')->count(),
                'unreconciled' => (clone $payments)->whereIn('reconciliation_status', ['pending', 'unmatched'])->count(),
                'credit_notes_issued' => round((float) B2bCreditNote::where('supplier_id', $user->id)
                    ->whereIn('status', ['issued', 'applied'])->sum('amount'), 2),
            ],
            'rfqs' => [
                'incoming_open' => Rfq::where('supplier_id', $user->id)
                    ->whereIn('status', ['submitted', 'under_review', 'supplier_responding'])->count(),
                'invitations_pending' => \App\Models\B2B\RfqInvitation::where('supplier_id', $user->id)
                    ->whereIn('status', ['invited', 'viewed'])->count(),
            ],
            'disputes' => [
                'open' => B2bDispute::where(function ($q) use ($user) {
                    $q->where('against_party_id', $user->id)->orWhereHas('order', function ($o) use ($user) {
                        $o->where('supplier_id', $user->id);
                    });
                })->whereIn('status', ['opened', 'under_investigation', 'arbitration', 'escalated'])->count(),
            ],
            'shipments' => [
                'active' => B2bShipment::where('supplier_id', $user->id)
                    ->whereNotIn('status', ['delivered', 'cancelled'])->count(),
                'delayed_eta' => B2bShipment::where('supplier_id', $user->id)
                    ->whereNotNull('estimated_arrival')
                    ->where('estimated_arrival', '<', now()->toDateString())
                    ->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            ],
        ];
    }

    private function adminDashboard(User $user): array
    {
        return [
            'portal' => 'admin',
            'users' => [
                'total' => User::count(),
                'importers' => User::where('user_type', 'customer')->count(),
                'exporters' => User::where('user_type', 'seller')->count(),
                'staff' => User::whereIn('user_type', ['admin', 'staff'])->count(),
                'unverified' => User::whereNull('email_verified_at')->count(),
            ],
            'catalogue' => [
                'products' => Product::count(),
                'pending_moderation' => Product::where('publication_status', 'pending_approval')->count(),
                'published' => Product::where('published', 1)->count(),
            ],
            'rfq' => [
                'unassigned' => Rfq::whereNull('assigned_to')->count(),
                'open' => Rfq::whereIn('status', ['submitted', 'under_review', 'supplier_responding'])->count(),
            ],
            'trade' => [
                'orders' => B2bOrder::count(),
                'gmv' => round((float) B2bOrder::sum('total_amount'), 2),
                'orders_by_status' => B2bOrder::select('status', DB::raw('count(*) as total'))
                    ->groupBy('status')->orderByDesc('total')->get(),
                'invoices' => B2bInvoice::count(),
            ],
            'payments' => [
                'awaiting_verification' => B2bPayment::where('status', 'verification_required')->count(),
                'unreconciled' => B2bPayment::whereIn('reconciliation_status', ['pending', 'unmatched'])->count(),
            ],
            'disputes' => [
                'open' => B2bDispute::whereIn('status', ['opened', 'under_investigation', 'arbitration', 'escalated'])->count(),
                'total' => B2bDispute::count(),
            ],
            'shipments' => [
                'active' => B2bShipment::whereNotIn('status', ['delivered', 'cancelled'])->count(),
                'delayed' => B2bShipment::whereNotNull('estimated_arrival')
                    ->where('estimated_arrival', '<', now()->toDateString())
                    ->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            ],
        ];
    }

    /**
     * @return array{title:string,columns:array,rows:array,summary:array}
     */
    public function build(string $report, string $portal, User $user, array $filters = []): array
    {
        if (!array_key_exists($report, self::REPORTS)) {
            throw new \InvalidArgumentException("Unknown report '{$report}'");
        }

        $from = $filters['from'] ?? now()->subDays(90)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        return match ($report) {
            'sales_summary' => $this->salesSummary($portal, $user, $from, $to),
            'purchase_summary' => $this->purchaseSummary($portal, $user, $from, $to),
            'order_pipeline' => $this->orderPipeline($portal, $user, $from, $to),
            'rfq_performance' => $this->rfqPerformance($portal, $user, $from, $to),
            'inventory_valuation' => $this->inventoryValuation($portal, $user),
            'low_stock' => $this->lowStock($portal, $user),
            'inventory_ageing' => $this->inventoryAgeing($portal, $user),
            'shipment_performance' => $this->shipmentPerformance($portal, $user, $from, $to),
            'shipment_delays' => $this->shipmentDelays($portal, $user),
            'document_compliance' => $this->documentCompliance($portal, $user),
            'payment_reconciliation' => $this->paymentReconciliation($portal, $user, $from, $to),
            'dispute_summary' => $this->disputeSummary($portal, $user, $from, $to),
            'supplier_performance' => $this->supplierPerformance($user),
            'top_products' => $this->topProducts($portal, $user, $from, $to),
        };
    }

    private function orderScope(string $portal, User $user, string $from, string $to)
    {
        $query = B2bOrder::query()->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);

        if ($portal === 'importer') {
            $query->where('buyer_id', $user->id);
        } elseif ($portal === 'exporter') {
            $query->where('supplier_id', $user->id);
        }

        return $query;
    }

    private function salesSummary(string $portal, User $user, string $from, string $to): array
    {
        $rows = $this->orderScope($portal, $user, $from, $to)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as orders'),
                DB::raw('sum(total_amount) as order_value'),
                DB::raw('sum(amount_paid) as amount_paid'),
                DB::raw('sum(balance_due) as balance_due')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->toArray();

        return [
            'title' => self::REPORTS['sales_summary'],
            'columns' => ['date', 'orders', 'order_value', 'amount_paid', 'balance_due'],
            'rows' => $rows,
            'summary' => [
                'from' => $from,
                'to' => $to,
                'orders' => array_sum(array_column($rows, 'orders')),
                'order_value' => round(array_sum(array_map('floatval', array_column($rows, 'order_value'))), 2),
                'amount_paid' => round(array_sum(array_map('floatval', array_column($rows, 'amount_paid'))), 2),
            ],
        ];
    }

    private function purchaseSummary(string $portal, User $user, string $from, string $to): array
    {
        $rows = $this->orderScope($portal, $user, $from, $to)
            ->select('supplier_id', DB::raw('count(*) as orders'), DB::raw('sum(total_amount) as total_value'))
            ->groupBy('supplier_id')
            ->with('supplier:id,name')
            ->orderByDesc('total_value')
            ->get()
            ->map(function ($row) {
                return [
                    'supplier_id' => $row->supplier_id,
                    'supplier' => $row->supplier->name ?? null,
                    'orders' => (int) $row->orders,
                    'total_value' => (float) $row->total_value,
                ];
            })->toArray();

        return [
            'title' => self::REPORTS['purchase_summary'],
            'columns' => ['supplier_id', 'supplier', 'orders', 'total_value'],
            'rows' => $rows,
            'summary' => ['from' => $from, 'to' => $to, 'suppliers' => count($rows)],
        ];
    }

    private function orderPipeline(string $portal, User $user, string $from, string $to): array
    {
        $rows = $this->orderScope($portal, $user, $from, $to)
            ->select('status', DB::raw('count(*) as orders'), DB::raw('sum(total_amount) as value'))
            ->groupBy('status')
            ->orderByDesc('orders')
            ->get()
            ->toArray();

        return [
            'title' => self::REPORTS['order_pipeline'],
            'columns' => ['status', 'orders', 'value'],
            'rows' => $rows,
            'summary' => ['from' => $from, 'to' => $to, 'total_orders' => array_sum(array_column($rows, 'orders'))],
        ];
    }

    private function rfqPerformance(string $portal, User $user, string $from, string $to): array
    {
        $query = Rfq::query()->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);
        if ($portal === 'importer') {
            $query->where('buyer_id', $user->id);
        } elseif ($portal === 'exporter') {
            $query->whereHas('invitations', function ($q) use ($user) {
                $q->where('supplier_id', $user->id);
            })->orWhere('supplier_id', $user->id);
        }

        $rows = $query->select('status', DB::raw('count(*) as rfqs'), DB::raw('avg(requested_quantity) as avg_quantity'))
            ->groupBy('status')
            ->get()
            ->toArray();

        $total = array_sum(array_column($rows, 'rfqs'));
        $converted = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'converted') {
                $converted = $row['rfqs'];
            }
        }

        return [
            'title' => self::REPORTS['rfq_performance'],
            'columns' => ['status', 'rfqs', 'avg_quantity'],
            'rows' => $rows,
            'summary' => [
                'from' => $from,
                'to' => $to,
                'total_rfqs' => $total,
                'converted' => $converted,
                'conversion_rate' => $total > 0 ? round(($converted / $total) * 100, 2) : 0,
            ],
        ];
    }

    private function inventoryValuation(string $portal, User $user): array
    {
        $query = ProductLot::query()->where('lot_status', 'active');
        if ($portal === 'exporter') {
            $query->where('supplier_id', $user->id);
        } elseif ($portal === 'importer') {
            $query->whereRaw('1 = 0'); // importers do not hold supplier stock
        }

        $rows = $query->select(
            'warehouse_location',
            'currency',
            DB::raw('count(*) as lots'),
            DB::raw('sum(quantity_available) as quantity'),
            DB::raw('sum(quantity_available * unit_price) as stock_value')
        )->groupBy('warehouse_location', 'currency')->get()->toArray();

        return [
            'title' => self::REPORTS['inventory_valuation'],
            'columns' => ['warehouse_location', 'currency', 'lots', 'quantity', 'stock_value'],
            'rows' => $rows,
            'summary' => [
                'lots' => array_sum(array_column($rows, 'lots')),
                'stock_value_lines' => count($rows),
            ],
        ];
    }

    private function lowStock(string $portal, User $user): array
    {
        $query = ProductLot::with('product:id,name,sku')->where('lot_status', 'active')
            ->where('low_stock_threshold', '>', 0)
            ->whereColumn('quantity_available', '<=', 'low_stock_threshold');

        if ($portal === 'exporter') {
            $query->where('supplier_id', $user->id);
        } elseif ($portal === 'importer') {
            $query->whereRaw('1 = 0');
        }

        $rows = $query->get()->map(function ($lot) {
            return [
                'lot_code' => $lot->lot_code,
                'product' => $lot->product->name ?? null,
                'warehouse_location' => $lot->warehouse_location,
                'quantity_available' => (float) $lot->quantity_available,
                'low_stock_threshold' => (float) $lot->low_stock_threshold,
                'unit' => $lot->unit,
            ];
        })->toArray();

        return [
            'title' => self::REPORTS['low_stock'],
            'columns' => ['lot_code', 'product', 'warehouse_location', 'quantity_available', 'low_stock_threshold', 'unit'],
            'rows' => $rows,
            'summary' => ['lots_below_threshold' => count($rows)],
        ];
    }

    private function inventoryAgeing(string $portal, User $user): array
    {
        $query = ProductLot::with('product:id,name')->where('lot_status', 'active')->whereNotNull('expiry_date');
        if ($portal === 'exporter') {
            $query->where('supplier_id', $user->id);
        } elseif ($portal === 'importer') {
            $query->whereRaw('1 = 0');
        }

        $rows = $query->orderBy('expiry_date')->get()->map(function ($lot) {
            $info = $lot->shelf_life;
            return [
                'lot_code' => $lot->lot_code,
                'product' => $lot->product->name ?? null,
                'expiry_date' => $lot->expiry_date ? $lot->expiry_date->toDateString() : null,
                'days_remaining' => $info['days_remaining'],
                'pct_elapsed' => $info['pct_elapsed'],
                'ageing_bucket' => $info['ageing_bucket'],
                'quantity_available' => (float) $lot->quantity_available,
                'unit' => $lot->unit,
            ];
        })->toArray();

        return [
            'title' => self::REPORTS['inventory_ageing'],
            'columns' => ['lot_code', 'product', 'expiry_date', 'days_remaining', 'pct_elapsed', 'ageing_bucket', 'quantity_available', 'unit'],
            'rows' => $rows,
            'summary' => [
                'expired' => count(array_filter($rows, fn ($r) => $r['ageing_bucket'] === 'expired')),
                'critical_30d' => count(array_filter($rows, fn ($r) => $r['ageing_bucket'] === 'critical')),
                'aging_90d' => count(array_filter($rows, fn ($r) => $r['ageing_bucket'] === 'aging')),
            ],
        ];
    }

    private function shipmentPerformance(string $portal, User $user, string $from, string $to): array
    {
        $query = B2bShipment::query()->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);
        if ($portal === 'importer') {
            $query->where('buyer_id', $user->id);
        } elseif ($portal === 'exporter') {
            $query->where('supplier_id', $user->id);
        }

        $rows = (clone $query)->select('mode', 'status', DB::raw('count(*) as shipments'))
            ->groupBy('mode', 'status')->get()->toArray();

        $onTime = (clone $query)->whereNotNull('actual_arrival')->whereNotNull('estimated_arrival')
            ->whereColumn('actual_arrival', '<=', 'estimated_arrival')->count();
        $delivered = (clone $query)->where('status', 'delivered')->count();

        return [
            'title' => self::REPORTS['shipment_performance'],
            'columns' => ['mode', 'status', 'shipments'],
            'rows' => $rows,
            'summary' => [
                'from' => $from,
                'to' => $to,
                'delivered' => $delivered,
                'on_time' => $onTime,
                'on_time_rate' => $delivered > 0 ? round(($onTime / $delivered) * 100, 2) : 0,
            ],
        ];
    }

    private function shipmentDelays(string $portal, User $user): array
    {
        $query = B2bShipment::with('order:id,order_number')
            ->whereNotNull('estimated_arrival')
            ->where('estimated_arrival', '<', now()->toDateString())
            ->whereNotIn('status', ['delivered', 'cancelled']);

        if ($portal === 'importer') {
            $query->where('buyer_id', $user->id);
        } elseif ($portal === 'exporter') {
            $query->where('supplier_id', $user->id);
        }

        $rows = $query->orderBy('estimated_arrival')->get()->map(function ($shipment) {
            return [
                'shipment_number' => $shipment->shipment_number,
                'order_number' => $shipment->order->order_number ?? null,
                'status' => $shipment->status,
                'estimated_arrival' => $shipment->estimated_arrival ? $shipment->estimated_arrival->toDateString() : null,
                'days_late' => $shipment->estimated_arrival
                    ? (int) $shipment->estimated_arrival->diffInDays(now()->startOfDay())
                    : null,
            ];
        })->toArray();

        return [
            'title' => self::REPORTS['shipment_delays'],
            'columns' => ['shipment_number', 'order_number', 'status', 'estimated_arrival', 'days_late'],
            'rows' => $rows,
            'summary' => ['delayed_shipments' => count($rows)],
        ];
    }

    private function documentCompliance(string $portal, User $user): array
    {
        $query = B2bTradeDocument::query();
        if ($portal === 'importer') {
            $query->whereHas('order', function ($q) use ($user) {
                $q->where('buyer_id', $user->id);
            });
        } elseif ($portal === 'exporter') {
            $query->whereHas('order', function ($q) use ($user) {
                $q->where('supplier_id', $user->id);
            });
        }

        $rows = $query->select(
            'document_type',
            DB::raw('count(*) as documents'),
            DB::raw("sum(case when status = 'expired' then 1 else 0 end) as expired"),
            DB::raw("sum(case when expiry_date is not null and expiry_date < curdate() then 1 else 0 end) as past_expiry")
        )->groupBy('document_type')->orderByDesc('documents')->get()->toArray();

        return [
            'title' => self::REPORTS['document_compliance'],
            'columns' => ['document_type', 'documents', 'expired', 'past_expiry'],
            'rows' => $rows,
            'summary' => [
                'document_types' => count($rows),
                'documents' => array_sum(array_column($rows, 'documents')),
                'past_expiry' => array_sum(array_column($rows, 'past_expiry')),
            ],
        ];
    }

    private function paymentReconciliation(string $portal, User $user, string $from, string $to): array
    {
        $query = B2bPayment::query()->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);
        if ($portal === 'importer') {
            $query->where('buyer_id', $user->id);
        } elseif ($portal === 'exporter') {
            $query->where('supplier_id', $user->id);
        }

        $rows = $query->select(
            'currency',
            'reconciliation_status',
            DB::raw('count(*) as payments'),
            DB::raw('sum(amount) as amount'),
            DB::raw('sum(coalesce(base_amount, amount)) as base_amount')
        )->groupBy('currency', 'reconciliation_status')->get()->toArray();

        return [
            'title' => self::REPORTS['payment_reconciliation'],
            'columns' => ['currency', 'reconciliation_status', 'payments', 'amount', 'base_amount'],
            'rows' => $rows,
            'summary' => [
                'from' => $from,
                'to' => $to,
                'payments' => array_sum(array_column($rows, 'payments')),
                'credit_notes' => round((float) B2bCreditNote::when($portal === 'exporter', function ($q) use ($user) {
                    $q->where('supplier_id', $user->id);
                })->when($portal === 'importer', function ($q) use ($user) {
                    $q->where('buyer_id', $user->id);
                })->whereIn('status', ['issued', 'applied'])->sum('amount'), 2),
            ],
        ];
    }

    private function disputeSummary(string $portal, User $user, string $from, string $to): array
    {
        $query = B2bDispute::query()->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);
        if ($portal === 'importer') {
            $query->where(function ($q) use ($user) {
                $q->where('raised_by_id', $user->id)->orWhereHas('order', function ($o) use ($user) {
                    $o->where('buyer_id', $user->id);
                });
            });
        } elseif ($portal === 'exporter') {
            $query->where(function ($q) use ($user) {
                $q->where('against_party_id', $user->id)->orWhereHas('order', function ($o) use ($user) {
                    $o->where('supplier_id', $user->id);
                });
            });
        }

        $rows = $query->select('dispute_type', 'status', DB::raw('count(*) as disputes'), DB::raw('sum(claim_amount) as claim_amount'))
            ->groupBy('dispute_type', 'status')->get()->toArray();

        return [
            'title' => self::REPORTS['dispute_summary'],
            'columns' => ['dispute_type', 'status', 'disputes', 'claim_amount'],
            'rows' => $rows,
            'summary' => [
                'from' => $from,
                'to' => $to,
                'disputes' => array_sum(array_column($rows, 'disputes')),
                'claim_amount' => round(array_sum(array_map('floatval', array_column($rows, 'claim_amount'))), 2),
            ],
        ];
    }

    private function supplierPerformance(User $user): array
    {
        $rows = B2bOrder::select(
            'supplier_id',
            DB::raw('count(*) as orders'),
            DB::raw('sum(total_amount) as order_value'),
            DB::raw('sum(case when status = "delivered" then 1 else 0 end) as delivered')
        )->groupBy('supplier_id')->with('supplier:id,name')->orderByDesc('order_value')->limit(50)->get()
            ->map(function ($row) {
                return [
                    'supplier_id' => $row->supplier_id,
                    'supplier' => $row->supplier->name ?? null,
                    'orders' => (int) $row->orders,
                    'delivered' => (int) $row->delivered,
                    'order_value' => (float) $row->order_value,
                    'fulfilment_rate' => $row->orders > 0 ? round(($row->delivered / $row->orders) * 100, 2) : 0,
                ];
            })->toArray();

        return [
            'title' => self::REPORTS['supplier_performance'],
            'columns' => ['supplier_id', 'supplier', 'orders', 'delivered', 'order_value', 'fulfilment_rate'],
            'rows' => $rows,
            'summary' => ['suppliers' => count($rows)],
        ];
    }

    private function topProducts(string $portal, User $user, string $from, string $to): array
    {
        $query = DB::table('b2b_order_items as oi')
            ->join('b2b_orders as o', 'o.id', '=', 'oi.order_id')
            ->whereBetween(DB::raw('DATE(o.created_at)'), [$from, $to]);

        if ($portal === 'importer') {
            $query->where('o.buyer_id', $user->id);
        } elseif ($portal === 'exporter') {
            $query->where('o.supplier_id', $user->id);
        }

        $rows = $query->select(
            'oi.product_id',
            'oi.product_name_snapshot as product',
            DB::raw('sum(oi.quantity) as quantity'),
            DB::raw('sum(oi.line_total) as revenue')
        )->groupBy('oi.product_id', 'oi.product_name_snapshot')
            ->orderByDesc('revenue')->limit(25)->get()->map(function ($row) {
                return (array) $row;
            })->toArray();

        return [
            'title' => self::REPORTS['top_products'],
            'columns' => ['product_id', 'product', 'quantity', 'revenue'],
            'rows' => $rows,
            'summary' => ['rows' => count($rows), 'from' => $from, 'to' => $to],
        ];
    }
}

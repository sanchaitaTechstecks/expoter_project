<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Companies Table
        if (!Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->index();
                $table->string('name');
                $table->string('legal_name')->nullable();
                $table->string('company_type')->default('both'); // importer, exporter, both
                $table->string('trade_type')->default('both'); // domestic, international, both
                $table->string('country')->nullable();
                $table->string('iec_code')->nullable(); // Import Export Code
                $table->string('tax_number')->nullable(); // GST / VAT
                $table->string('registration_number')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contact_phone')->nullable();
                $table->string('website')->nullable();
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('postal_code')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('bank_account_name')->nullable();
                $table->string('bank_account_number')->nullable();
                $table->string('swift_bic')->nullable();
                $table->string('iban')->nullable();
                $table->string('bank_branch')->nullable();
                $table->string('verification_status')->default('unverified'); // unverified, pending, verified, rejected
                $table->text('verification_notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 2. Add B2B columns to products if missing
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'sku')) {
                $table->string('sku')->nullable()->after('name');
            }
            if (!Schema::hasColumn('products', 'grade')) {
                $table->string('grade')->nullable()->after('sku');
            }
            if (!Schema::hasColumn('products', 'origin_country')) {
                $table->string('origin_country')->nullable()->after('grade');
            }
            if (!Schema::hasColumn('products', 'packaging_type')) {
                $table->string('packaging_type')->nullable()->after('origin_country');
            }
            if (!Schema::hasColumn('products', 'specifications')) {
                $table->text('specifications')->nullable()->after('packaging_type');
            }
            if (!Schema::hasColumn('products', 'domestic_visibility')) {
                $table->boolean('domestic_visibility')->default(true)->after('specifications');
            }
            if (!Schema::hasColumn('products', 'export_visibility')) {
                $table->boolean('export_visibility')->default(true)->after('domestic_visibility');
            }
            if (!Schema::hasColumn('products', 'publication_status')) {
                $table->string('publication_status')->default('active')->after('export_visibility');
            }
        });

        // 3. Product Lots Table
        if (!Schema::hasTable('product_lots')) {
            Schema::create('product_lots', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('lot_code')->unique();
                $table->unsignedInteger('product_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->string('warehouse_location')->nullable();
                $table->string('quality_grade')->nullable();
                $table->string('inspection_status')->default('pending'); // pending, inspected, passed, failed
                $table->decimal('quantity_received', 15, 3)->default(0);
                $table->decimal('quantity_reserved', 15, 3)->default(0);
                $table->decimal('quantity_dispatched', 15, 3)->default(0);
                $table->decimal('quantity_available', 15, 3)->default(0);
                $table->string('unit')->default('kg');
                $table->decimal('unit_price', 15, 2);
                $table->string('currency', 10)->default('USD');
                $table->decimal('domestic_price', 15, 2)->nullable();
                $table->decimal('export_price', 15, 2)->nullable();
                $table->string('incoterm', 20)->nullable(); // FOB, CIF, CFR, EXW
                $table->string('named_place')->nullable();
                $table->string('packaging_details')->nullable();
                $table->date('manufacture_or_harvest_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->json('certificates')->nullable();
                $table->json('photos')->nullable();
                $table->string('lot_status')->default('active'); // draft, pending_approval, active, reserved, depleted, blocked, archived
                $table->timestamp('uploaded_at')->nullable();
                $table->timestamp('effective_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 4. Price Histories Table
        if (!Schema::hasTable('price_histories')) {
            Schema::create('price_histories', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('lot_id')->index();
                $table->decimal('old_price', 15, 2);
                $table->decimal('new_price', 15, 2);
                $table->string('currency', 10)->default('USD');
                $table->string('reason')->nullable();
                $table->unsignedInteger('actor_id')->index();
                $table->timestamp('effective_at')->nullable();
                $table->string('approval_status')->default('approved');
                $table->timestamps();
            });
        }

        // 5. Inventory Movements Table
        if (!Schema::hasTable('inventory_movements')) {
            Schema::create('inventory_movements', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('lot_id')->index();
                $table->string('movement_type'); // received, reserved, released, dispatched, delivered, returned, adjusted, damage
                $table->decimal('quantity', 15, 3);
                $table->string('unit')->default('kg');
                $table->string('reference_type')->nullable(); // order, rfq, manual
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->text('reason')->nullable();
                $table->unsignedInteger('actor_id')->index();
                $table->timestamps();
            });
        }

        // 6. RFQs Table
        if (!Schema::hasTable('rfqs')) {
            Schema::create('rfqs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('rfq_number')->unique();
                $table->unsignedInteger('buyer_id')->index();
                $table->unsignedInteger('supplier_id')->nullable()->index();
                $table->unsignedInteger('product_id')->index();
                $table->json('preferred_lot_ids')->nullable();
                $table->decimal('requested_quantity', 15, 3);
                $table->string('unit')->default('kg');
                $table->decimal('target_price', 15, 2)->nullable();
                $table->text('quality_specification')->nullable();
                $table->string('packaging_requirement')->nullable();
                $table->string('trade_type')->default('international'); // domestic, international
                $table->string('destination_country')->nullable();
                $table->string('destination_port_or_address')->nullable();
                $table->string('requested_delivery_window')->nullable();
                $table->string('incoterm', 20)->nullable();
                $table->string('named_place')->nullable();
                $table->string('currency', 10)->default('USD');
                $table->json('required_documents')->nullable();
                $table->text('notes')->nullable();
                $table->json('attachments')->nullable();
                $table->string('status')->default('submitted'); // draft, submitted, under_review, clarification_needed, supplier_responding, quotation_issued, accepted, rejected, expired, converted, closed
                $table->unsignedInteger('assigned_to')->nullable();
                $table->unsignedInteger('assigned_by')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('draft_saved_at')->nullable();
                $table->text('draft_notes')->nullable();
                $table->string('rfq_type')->default('standard'); // standard, bulk, repeat, stock, sample, sample_stock, sample_bulk, stock_bulk, stock_sample, stock_sample_bulk
                $table->string('source_channel')->nullable(); // web, whatsapp, store, email, api, other
                $table->json('dec013_export_offers')->nullable(); // DEC-013: array of supplier offers with price/null, validity, incoterm, lead_time
                $table->string('dec013_external_id')->nullable(); // DEC-013 supplier/offer external reference
                $table->unsignedTinyInteger('offer_count')->default(0); // DEC-013 total offers received
                $table->unsignedTinyInteger('offer_by_supplier')->nullable(); // DEC-013 count of offers from a single supplier
                $table->text('rejection_reason')->nullable(); // RFQ-stage acceptance/rejection reason
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 7. RFQ Messages Table
        if (!Schema::hasTable('rfq_messages')) {
            Schema::create('rfq_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rfq_id')->index();
                $table->unsignedInteger('sender_id')->index();
                $table->text('message');
                $table->json('attachments')->nullable();
                $table->timestamps();
            });
        }

        // 8. B2B Quotations Table
        if (!Schema::hasTable('b2b_quotations')) {
            Schema::create('b2b_quotations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('quotation_number')->unique();
                $table->unsignedBigInteger('rfq_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->unsignedInteger('buyer_id')->index();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->integer('version')->default(1);
                $table->string('currency', 10)->default('USD');
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('tax', 15, 2)->default(0);
                $table->decimal('freight_charges', 15, 2)->default(0);
                $table->decimal('insurance_charges', 15, 2)->default(0);
                $table->decimal('other_charges', 15, 2)->default(0);
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->string('incoterm', 20)->nullable();
                $table->string('named_place')->nullable();
                $table->dateTime('validity_date')->nullable();
                $table->string('delivery_window')->nullable();
                $table->text('payment_terms')->nullable();
                $table->text('exclusions')->nullable();
                $table->text('notes')->nullable();
                $table->string('status')->default('issued'); // draft, pending_approval, issued, accepted, rejected, expired, superseded, cancelled
                $table->text('rejection_reason')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->unsignedBigInteger('superseded_by_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 9. B2B Quotation Items Table
        if (!Schema::hasTable('b2b_quotation_items')) {
            Schema::create('b2b_quotation_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('quotation_id')->index();
                $table->unsignedInteger('product_id')->index();
                $table->unsignedBigInteger('lot_id')->nullable()->index();
                $table->string('product_name_snapshot');
                $table->text('specification_snapshot')->nullable();
                $table->decimal('quantity', 15, 3);
                $table->string('unit')->default('kg');
                $table->decimal('unit_price', 15, 2);
                $table->decimal('line_total', 15, 2);
                $table->string('packaging')->nullable();
                $table->timestamps();
            });
        }

        // 10. B2B Orders Table
        if (!Schema::hasTable('b2b_orders')) {
            Schema::create('b2b_orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('order_number')->unique();
                $table->unsignedInteger('buyer_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->unsignedBigInteger('source_rfq_id')->nullable()->index();
                $table->unsignedBigInteger('accepted_quotation_id')->nullable()->index();
                $table->unsignedBigInteger('agreement_id')->nullable()->index();
                $table->date('order_date');
                $table->string('order_type')->default('international'); // domestic, international
                $table->string('status')->default('confirmed'); // pending_acceptance, confirmed, on_hold, awaiting_payment, partially_paid, preparing, ready_for_dispatch, in_fulfilment, partially_shipped, shipped, partially_delivered, delivered, completed, cancelled, disputed
                $table->string('currency', 10)->default('USD');
                $table->string('incoterm', 20)->nullable();
                $table->string('named_place')->nullable();
                $table->decimal('total_quantity', 15, 3)->default(0);
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('discounts', 15, 2)->default(0);
                $table->decimal('taxes', 15, 2)->default(0);
                $table->decimal('freight', 15, 2)->default(0);
                $table->decimal('insurance', 15, 2)->default(0);
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->decimal('amount_paid', 15, 2)->default(0);
                $table->decimal('balance_due', 15, 2)->default(0);
                $table->string('payment_status')->default('unpaid'); // unpaid, partially_paid, paid
                $table->date('expected_delivery')->nullable();
                $table->string('destination_country')->nullable();
                $table->string('destination_port_or_address')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 11. B2B Order Items Table
        if (!Schema::hasTable('b2b_order_items')) {
            Schema::create('b2b_order_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedInteger('product_id')->index();
                $table->unsignedBigInteger('lot_id')->nullable()->index();
                $table->string('product_name_snapshot');
                $table->text('specification_snapshot')->nullable();
                $table->decimal('quantity', 15, 3);
                $table->string('unit')->default('kg');
                $table->decimal('unit_price', 15, 2);
                $table->decimal('line_total', 15, 2);
                $table->decimal('allocated_quantity', 15, 3)->default(0);
                $table->decimal('dispatched_quantity', 15, 3)->default(0);
                $table->decimal('delivered_quantity', 15, 3)->default(0);
                $table->decimal('outstanding_quantity', 15, 3)->default(0);
                $table->timestamps();
            });
        }

        // 12. B2B Agreements Table
        if (!Schema::hasTable('b2b_agreements')) {
            Schema::create('b2b_agreements', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('agreement_number')->unique();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('quotation_id')->nullable()->index();
                $table->unsignedInteger('buyer_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->integer('version')->default(1);
                $table->text('terms_and_conditions');
                $table->string('incoterm', 20)->nullable();
                $table->string('named_place')->nullable();
                $table->decimal('total_value', 15, 2);
                $table->string('currency', 10)->default('USD');
                $table->text('payment_schedule')->nullable();
                $table->string('buyer_signature')->nullable();
                $table->timestamp('buyer_signed_at')->nullable();
                $table->string('supplier_signature')->nullable();
                $table->timestamp('supplier_signed_at')->nullable();
                $table->string('status')->default('sent_to_counterparty'); // draft, sent_to_counterparty, accepted, rejected, expired, cancelled
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 13. B2B Invoices Table
        if (!Schema::hasTable('b2b_invoices')) {
            Schema::create('b2b_invoices', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('invoice_number')->unique();
                $table->string('invoice_type')->default('proforma'); // proforma, commercial, tax
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedInteger('buyer_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->date('issue_date');
                $table->date('due_date')->nullable();
                $table->string('currency', 10)->default('USD');
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('tax', 15, 2)->default(0);
                $table->decimal('freight', 15, 2)->default(0);
                $table->decimal('insurance', 15, 2)->default(0);
                $table->decimal('other_charges', 15, 2)->default(0);
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->json('bank_details')->nullable();
                $table->text('payment_terms')->nullable();
                $table->string('status')->default('issued'); // draft, issued, paid, partially_paid, cancelled
                $table->string('file_url')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 14. B2B Payments Table
        if (!Schema::hasTable('b2b_payments')) {
            Schema::create('b2b_payments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('payment_number')->unique();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedInteger('buyer_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 10)->default('USD');
                $table->string('payment_type')->default('advance'); // advance, deposit, partial, balance, full
                $table->string('payment_method')->default('bank_transfer'); // bank_transfer, letter_of_credit, online, cheque, other
                $table->string('transaction_reference');
                $table->string('proof_file')->nullable();
                $table->date('due_date')->nullable();
                $table->timestamp('initiated_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->string('status')->default('pending'); // draft, pending, initiated, verification_required, partially_paid, paid, failed, rejected, refunded
                $table->unsignedInteger('verified_by')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // 15. B2B Trade Documents Table
        if (!Schema::hasTable('b2b_trade_documents')) {
            Schema::create('b2b_trade_documents', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('document_type'); // quotation, proforma_invoice, packing_list, tax_invoice, commercial_invoice, phytosanitary_certificate, fumigation_certificate, certificate_of_origin, bill_of_lading, inspection_certificate, insurance_certificate, delivery_proof, other
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('shipment_id')->nullable()->index();
                $table->unsignedBigInteger('lot_id')->nullable()->index();
                $table->unsignedInteger('uploader_id')->index();
                $table->string('document_number')->nullable();
                $table->string('title');
                $table->string('file_path');
                $table->string('file_name')->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->string('mime_type')->nullable();
                $table->date('issue_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('issuer')->nullable();
                $table->integer('version')->default(1);
                $table->string('status')->default('uploaded'); // uploaded, under_review, approved, rejected, expired, superseded
                $table->text('review_comments')->nullable();
                $table->unsignedInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 16. B2B Shipments Table
        if (!Schema::hasTable('b2b_shipments')) {
            Schema::create('b2b_shipments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('shipment_number')->unique();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->unsignedInteger('buyer_id')->index();
                $table->string('mode')->default('sea'); // sea, air, road, rail, multimodal
                $table->string('carrier_name')->nullable();
                $table->string('freight_forwarder')->nullable();
                $table->string('booking_reference')->nullable();
                $table->string('tracking_number')->nullable();
                $table->string('vessel_name')->nullable();
                $table->string('voyage_number')->nullable();
                $table->string('origin_port')->nullable();
                $table->text('origin_address')->nullable();
                $table->string('destination_port')->nullable();
                $table->text('final_destination')->nullable();
                $table->date('departure_date')->nullable();
                $table->date('estimated_arrival')->nullable();
                $table->date('actual_arrival')->nullable();
                $table->string('status')->default('planned'); // planned, booking_confirmed, goods_preparing, ready, picked_up, at_origin_port, customs_processing, loaded, departed, in_transit, transshipment, at_destination_port, customs_hold, cleared, out_for_delivery, delivered, delayed, cancelled
                $table->string('assigned_cha')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 17. B2B Containers Table
        if (!Schema::hasTable('b2b_containers')) {
            Schema::create('b2b_containers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('shipment_id')->index();
                $table->string('container_number');
                $table->string('container_type')->default('20FT');
                $table->string('seal_number')->nullable();
                $table->decimal('loaded_quantity', 15, 3)->default(0);
                $table->string('unit')->default('kg');
                $table->decimal('gross_weight', 15, 3)->nullable();
                $table->decimal('net_weight', 15, 3)->nullable();
                $table->string('current_location')->nullable();
                $table->string('status')->default('assigned'); // assigned, loaded, in_transit, customs_hold, cleared, unloaded
                $table->timestamps();
            });
        }

        // 18. B2B Tracking Events Table
        if (!Schema::hasTable('b2b_tracking_events')) {
            Schema::create('b2b_tracking_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedBigInteger('container_id')->nullable()->index();
                $table->string('event_type'); // order_received, payment_received, goods_ready, vehicle_assigned, origin_port_reached, customs_processing, loaded_onto_vessel, vessel_departed, in_transit, transshipment, destination_port_reached, customs_cleared, out_for_delivery, delivered, delayed, exception
                $table->string('location');
                $table->string('event_status')->default('completed'); // completed, in_progress, scheduled, delayed
                $table->dateTime('planned_at')->nullable();
                $table->dateTime('actual_at')->nullable();
                $table->string('source')->default('manual'); // manual, carrier_api, gps
                $table->text('description')->nullable();
                $table->string('evidence_attachment')->nullable();
                $table->unsignedInteger('created_by')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_tracking_events');
        Schema::dropIfExists('b2b_containers');
        Schema::dropIfExists('b2b_shipments');
        Schema::dropIfExists('b2b_trade_documents');
        Schema::dropIfExists('b2b_payments');
        Schema::dropIfExists('b2b_invoices');
        Schema::dropIfExists('b2b_agreements');
        Schema::dropIfExists('b2b_order_items');
        Schema::dropIfExists('b2b_orders');
        Schema::dropIfExists('b2b_quotation_items');
        Schema::dropIfExists('b2b_quotations');
        Schema::dropIfExists('rfq_messages');
        Schema::dropIfExists('rfqs');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('price_histories');
        Schema::dropIfExists('product_lots');
        Schema::dropIfExists('companies');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'sku',
                'grade',
                'origin_country',
                'packaging_type',
                'specifications',
                'domestic_visibility',
                'export_visibility',
                'publication_status',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 remediation schema: inventory operations, shipment operations,
 * document checklists, payment adjustments, RFQ broadcast, account security.
 */
return new class extends Migration
{
    public function up(): void
    {
        // P2-3: product gallery images
        if (!Schema::hasTable('product_gallery_images')) {
            Schema::create('product_gallery_images', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('product_id')->index();
                $table->string('file_path');
                $table->string('file_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_primary')->default(false);
                $table->unsignedInteger('uploaded_by')->nullable();
                $table->timestamps();
            });
        }

        // P2-3: bulk CSV import preview batches
        if (!Schema::hasTable('b2b_bulk_import_batches')) {
            Schema::create('b2b_bulk_import_batches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->index();
                $table->string('token', 64)->unique();
                $table->string('original_filename')->nullable();
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('valid_rows')->default(0);
                $table->unsignedInteger('invalid_rows')->default(0);
                $table->string('status')->default('previewed'); // previewed, committed, failed
                $table->json('rows')->nullable();
                $table->timestamps();
            });
        }

        // P2-4: low stock thresholds on lots
        if (!Schema::hasColumn('product_lots', 'low_stock_threshold')) {
            Schema::table('product_lots', function (Blueprint $table) {
                $table->decimal('low_stock_threshold', 15, 3)->default(0)->after('quantity_available');
            });
        }

        // P2-4: warehouse transfers
        if (!Schema::hasTable('b2b_warehouse_transfers')) {
            Schema::create('b2b_warehouse_transfers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('transfer_number')->unique();
                $table->unsignedInteger('supplier_id')->index();
                $table->string('from_warehouse');
                $table->string('to_warehouse');
                $table->string('status')->default('draft'); // draft, in_transit, completed, cancelled
                $table->text('notes')->nullable();
                $table->unsignedInteger('requested_by')->nullable();
                $table->unsignedInteger('completed_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('b2b_warehouse_transfer_items')) {
            Schema::create('b2b_warehouse_transfer_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('transfer_id')->index();
                $table->unsignedBigInteger('lot_id')->index();
                $table->decimal('quantity', 15, 3);
                $table->string('unit', 20)->default('kg');
                $table->timestamps();
            });
        }

        // P2-4: unit conversion master
        if (!Schema::hasTable('b2b_unit_conversions')) {
            Schema::create('b2b_unit_conversions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('from_unit', 20);
                $table->string('to_unit', 20);
                $table->decimal('factor', 20, 8);
                $table->string('category')->nullable(); // weight, volume, count, length
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['from_unit', 'to_unit']);
            });
        }

        // P2-5: ETA revision audit trail
        if (!Schema::hasTable('b2b_shipment_eta_revisions')) {
            Schema::create('b2b_shipment_eta_revisions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('shipment_id')->index();
                $table->date('previous_eta')->nullable();
                $table->date('new_eta');
                $table->text('reason')->nullable();
                $table->unsignedInteger('revised_by')->nullable();
                $table->timestamps();
            });
        }

        // P2-5: operational event timezone capture
        if (!Schema::hasColumn('b2b_tracking_events', 'timezone')) {
            Schema::table('b2b_tracking_events', function (Blueprint $table) {
                $table->string('timezone', 64)->nullable()->after('actual_at');
                $table->dateTime('event_time_local')->nullable()->after('timezone');
                $table->dateTime('actual_at_utc')->nullable()->after('event_time_local');
            });
        }

        // P2-5: container assignment history
        if (!Schema::hasTable('b2b_container_assignments')) {
            Schema::create('b2b_container_assignments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('container_id')->index();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedBigInteger('previous_shipment_id')->nullable();
                $table->string('action')->default('assigned'); // assigned, reassigned, released
                $table->unsignedInteger('actor_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // P2-5: vehicle / driver assignment
        if (!Schema::hasTable('b2b_shipment_vehicles')) {
            Schema::create('b2b_shipment_vehicles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('shipment_id')->index();
                $table->string('vehicle_type')->nullable(); // truck, trailer, van, rail_wagon
                $table->string('vehicle_number');
                $table->string('driver_name')->nullable();
                $table->string('driver_phone', 30)->nullable();
                $table->string('driver_license')->nullable();
                $table->decimal('capacity', 15, 3)->nullable();
                $table->string('unit', 20)->default('kg');
                $table->string('status')->default('assigned'); // assigned, released
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->unsignedInteger('actor_id')->nullable();
                $table->timestamps();
            });
        }

        // P2-6: document checklist templates
        if (!Schema::hasTable('b2b_document_checklist_templates')) {
            Schema::create('b2b_document_checklist_templates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('commodity')->nullable();
                $table->string('trade_type', 20)->default('international'); // domestic, international, both
                $table->string('origin_country')->nullable();
                $table->string('destination_country')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('b2b_document_checklist_template_items')) {
            Schema::create('b2b_document_checklist_template_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('template_id')->index();
                $table->string('document_type');
                $table->boolean('is_mandatory')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // P2-7: credit notes / refunds
        if (!Schema::hasTable('b2b_credit_notes')) {
            Schema::create('b2b_credit_notes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('credit_note_number')->unique();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('dispute_id')->nullable()->index();
                $table->unsignedBigInteger('payment_id')->nullable()->index();
                $table->unsignedInteger('buyer_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->string('type')->default('adjustment'); // refund, credit, damage_refund, shortage_refund, fx_adjustment, adjustment
                $table->decimal('amount', 15, 2);
                $table->string('currency', 10)->default('USD');
                $table->decimal('exchange_rate', 18, 8)->nullable();
                $table->string('base_currency', 10)->nullable();
                $table->decimal('base_amount', 15, 2)->nullable();
                $table->text('reason')->nullable();
                $table->string('status')->default('draft'); // draft, issued, applied, void
                $table->unsignedInteger('issued_by')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('b2b_credit_note_items')) {
            Schema::create('b2b_credit_note_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('credit_note_id')->index();
                $table->string('description');
                $table->decimal('quantity', 15, 3)->nullable();
                $table->string('unit', 20)->nullable();
                $table->decimal('unit_price', 15, 2)->nullable();
                $table->decimal('line_total', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        // P2-7: FX capture + bank reconciliation on payments
        if (!Schema::hasColumn('b2b_payments', 'exchange_rate')) {
            Schema::table('b2b_payments', function (Blueprint $table) {
                $table->decimal('exchange_rate', 18, 8)->nullable()->after('currency');
                $table->string('base_currency', 10)->nullable()->after('exchange_rate');
                $table->decimal('base_amount', 15, 2)->nullable()->after('base_currency');
                $table->timestamp('transaction_at')->nullable()->after('received_at');
                $table->string('reconciliation_status')->default('pending')->after('status'); // pending, matched, unmatched, disputed, not_required
                $table->string('bank_statement_reference')->nullable()->after('reconciliation_status');
                $table->unsignedInteger('reconciled_by')->nullable()->after('bank_statement_reference');
                $table->timestamp('reconciled_at')->nullable()->after('reconciled_by');
            });
        }

        // P2-2: RFQ multi-supplier broadcast invitations
        if (!Schema::hasTable('rfq_invitations')) {
            Schema::create('rfq_invitations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rfq_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->string('status')->default('invited'); // invited, viewed, responded, declined
                $table->text('decline_reason')->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->timestamps();
                $table->unique(['rfq_id', 'supplier_id']);
            });
        }

        if (!Schema::hasColumn('rfqs', 'rejection_code')) {
            Schema::table('rfqs', function (Blueprint $table) {
                $table->string('rejection_code', 50)->nullable();
            });
        }

        // The legacy rfqs table predates the platform schema, so restore the
        // workflow columns the RFQ controllers depend on.
        if (!Schema::hasColumn('rfqs', 'draft_saved_at') || !Schema::hasColumn('rfqs', 'rfq_type')) {
            Schema::table('rfqs', function (Blueprint $table) {
                if (!Schema::hasColumn('rfqs', 'draft_saved_at')) {
                    $table->timestamp('draft_saved_at')->nullable();
                }
                if (!Schema::hasColumn('rfqs', 'draft_notes')) {
                    $table->text('draft_notes')->nullable();
                }
                if (!Schema::hasColumn('rfqs', 'rfq_type')) {
                    $table->string('rfq_type', 30)->nullable();
                }
                if (!Schema::hasColumn('rfqs', 'source_channel')) {
                    $table->string('source_channel', 50)->nullable();
                }
                if (!Schema::hasColumn('rfqs', 'offer_count')) {
                    $table->unsignedInteger('offer_count')->default(0);
                }
                if (!Schema::hasColumn('rfqs', 'offer_by_supplier')) {
                    $table->unsignedInteger('offer_by_supplier')->default(0);
                }
                if (!Schema::hasColumn('rfqs', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable();
                }
                if (!Schema::hasColumn('rfqs', 'assigned_by')) {
                    $table->unsignedInteger('assigned_by')->nullable();
                }
                if (!Schema::hasColumn('rfqs', 'assigned_at')) {
                    $table->timestamp('assigned_at')->nullable();
                }
                if (!Schema::hasColumn('rfqs', 'dec013_export_offers')) {
                    $table->json('dec013_export_offers')->nullable();
                }
                if (!Schema::hasColumn('rfqs', 'dec013_external_id')) {
                    $table->string('dec013_external_id')->nullable();
                }
            });
        }

        // P2-8: account security
        if (!Schema::hasColumn('users', 'two_factor_secret')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('two_factor_secret')->nullable();
                $table->boolean('two_factor_enabled')->default(false);
                $table->timestamp('two_factor_confirmed_at')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->timestamp('last_activity_at')->nullable();
            });
        }

        if (!Schema::hasTable('b2b_email_verification_tokens')) {
            Schema::create('b2b_email_verification_tokens', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->index();
                $table->string('token', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_email_verification_tokens');

        if (Schema::hasColumn('users', 'two_factor_secret')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn([
                    'two_factor_secret',
                    'two_factor_enabled',
                    'two_factor_confirmed_at',
                    'last_login_at',
                    'last_activity_at',
                ]);
            });
        }

        if (Schema::hasColumn('rfqs', 'rejection_code')) {
            Schema::table('rfqs', function (Blueprint $table) {
                $table->dropColumn('rejection_code');
            });
        }

        Schema::dropIfExists('rfq_invitations');

        if (Schema::hasColumn('b2b_payments', 'exchange_rate')) {
            Schema::table('b2b_payments', function (Blueprint $table) {
                $table->dropColumn([
                    'exchange_rate',
                    'base_currency',
                    'base_amount',
                    'transaction_at',
                    'reconciliation_status',
                    'bank_statement_reference',
                    'reconciled_by',
                    'reconciled_at',
                ]);
            });
        }

        Schema::dropIfExists('b2b_credit_note_items');
        Schema::dropIfExists('b2b_credit_notes');
        Schema::dropIfExists('b2b_document_checklist_template_items');
        Schema::dropIfExists('b2b_document_checklist_templates');
        Schema::dropIfExists('b2b_shipment_vehicles');
        Schema::dropIfExists('b2b_container_assignments');

        if (Schema::hasColumn('b2b_tracking_events', 'timezone')) {
            Schema::table('b2b_tracking_events', function (Blueprint $table) {
                $table->dropColumn(['timezone', 'event_time_local', 'actual_at_utc']);
            });
        }

        Schema::dropIfExists('b2b_shipment_eta_revisions');
        Schema::dropIfExists('b2b_unit_conversions');
        Schema::dropIfExists('b2b_warehouse_transfer_items');
        Schema::dropIfExists('b2b_warehouse_transfers');

        if (Schema::hasColumn('product_lots', 'low_stock_threshold')) {
            Schema::table('product_lots', function (Blueprint $table) {
                $table->dropColumn('low_stock_threshold');
            });
        }

        Schema::dropIfExists('b2b_bulk_import_batches');
        Schema::dropIfExists('product_gallery_images');
    }
};

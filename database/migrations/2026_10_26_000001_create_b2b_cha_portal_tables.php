<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add assigned_cha_id to b2b_shipments if not present
        Schema::table('b2b_shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('b2b_shipments', 'assigned_cha_id')) {
                $table->unsignedInteger('assigned_cha_id')->nullable()->index()->after('assigned_cha');
            }
            if (!Schema::hasColumn('b2b_shipments', 'customs_status')) {
                $table->string('customs_status', 50)->default('pending')->after('status');
            }
        });

        // 2. Customs Declarations / Filings Table (Section 15)
        if (!Schema::hasTable('b2b_customs_declarations')) {
            Schema::create('b2b_customs_declarations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedInteger('cha_user_id')->index();
                $table->string('filing_type', 50)->default('shipping_bill'); // shipping_bill, bill_of_entry
                $table->string('declaration_number', 100);
                $table->string('port_code', 50);
                $table->date('filing_date');
                $table->string('cha_license_number', 100)->nullable();
                $table->string('iec_code', 50)->nullable();
                $table->decimal('assessable_value', 15, 2)->default(0.00);
                $table->string('currency', 10)->default('USD');
                $table->decimal('basic_customs_duty', 15, 2)->default(0.00);
                $table->decimal('igst_amount', 15, 2)->default(0.00);
                $table->decimal('cess_amount', 15, 2)->default(0.00);
                $table->decimal('total_duty_payable', 15, 2)->default(0.00);
                $table->string('duty_payment_status', 50)->default('unpaid'); // unpaid, paid, exempt
                $table->string('duty_challan_number', 100)->nullable();
                $table->timestamp('duty_paid_at')->nullable();
                $table->string('examination_status', 50)->default('pending'); // pending, scheduled, in_progress, passed, query_raised, customs_hold
                $table->string('examination_order_no', 100)->nullable();
                $table->date('examination_date')->nullable();
                $table->string('examination_officer', 191)->nullable();
                $table->text('examination_remarks')->nullable();
                $table->text('query_description')->nullable();
                $table->text('query_response')->nullable();
                $table->string('clearance_status', 50)->default('pending'); // pending, query_raised, hold, cleared, leo_granted, ooc_granted
                $table->string('release_order_number', 100)->nullable(); // LEO or OOC number
                $table->timestamp('released_at')->nullable();
                $table->text('release_notes')->nullable();
                $table->json('customs_documents')->nullable();
                $table->json('checklist_snapshot')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_customs_declarations');

        Schema::table('b2b_shipments', function (Blueprint $table) {
            $table->dropColumn(['assigned_cha_id', 'customs_status']);
        });
    }
};

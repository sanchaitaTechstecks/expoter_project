<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Master HS Codes Table
        if (!Schema::hasTable('b2b_hs_codes')) {
            Schema::create('b2b_hs_codes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('hs_code', 50)->unique();
                $table->text('description');
                $table->unsignedInteger('category_id')->nullable()->index();
                $table->string('chapter', 20)->nullable();
                $table->string('heading', 20)->nullable();
                $table->decimal('basic_customs_duty_pct', 8, 2)->default(0.00);
                $table->decimal('cess_pct', 8, 2)->default(0.00);
                $table->decimal('igst_pct', 8, 2)->default(0.00);
                $table->decimal('export_duty_pct', 8, 2)->default(0.00);
                $table->string('policy_status', 50)->default('free'); // free, restricted, prohibited
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 2. Order Disputes Table
        if (!Schema::hasTable('b2b_disputes')) {
            Schema::create('b2b_disputes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('dispute_number', 50)->unique();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedInteger('raised_by_id')->index();
                $table->unsignedInteger('against_party_id')->index();
                $table->string('dispute_type', 100); // quality_mismatch, short_quantity, shipment_delay, payment_delay, damaged_goods, document_defect, other
                $table->string('status', 50)->default('opened'); // opened, under_investigation, arbitration, resolved, dismissed, escalated
                $table->string('title');
                $table->text('description');
                $table->decimal('claim_amount', 15, 2)->default(0.00);
                $table->string('currency', 10)->default('USD');
                $table->json('evidence_attachments')->nullable();
                $table->string('resolution_action', 100)->nullable(); // full_refund, partial_refund, replacement_dispatch, order_cancelled, terms_enforced, claim_rejected
                $table->decimal('settlement_amount', 15, 2)->nullable();
                $table->unsignedInteger('resolved_by_id')->nullable()->index();
                $table->text('resolution_notes')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 3. Dispute Messages / Admin Notes Table
        if (!Schema::hasTable('b2b_dispute_messages')) {
            Schema::create('b2b_dispute_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('dispute_id')->index();
                $table->unsignedInteger('sender_id')->index();
                $table->text('message');
                $table->json('attachments')->nullable();
                $table->boolean('is_internal_admin_note')->default(false);
                $table->timestamps();
            });
        }

        // 4. B2B System Audit Logs Table (SRS Section 19)
        if (!Schema::hasTable('b2b_audit_logs')) {
            Schema::create('b2b_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('actor_id')->nullable()->index();
                $table->string('actor_role', 50)->default('system'); // super_admin, sub_admin, developer_admin, supplier, importer, system
                $table->string('action', 100)->index(); // pricing_approval, supplier_verification, dispute_resolution, category_updated, role_changed, order_status_update
                $table->string('entity_type', 100)->index();
                $table->string('entity_id', 100)->index();
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->text('diff_summary')->nullable();
                $table->string('reason')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->string('request_id', 100)->nullable();
                $table->string('status', 30)->default('success'); // success, failed, denied
                $table->timestamps();
            });
        }

        // 5. User admin role and permissions columns
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'admin_role')) {
                $table->string('admin_role', 50)->nullable()->after('user_type');
            }
            if (!Schema::hasColumn('users', 'admin_permissions')) {
                $table->json('admin_permissions')->nullable()->after('admin_role');
            }
        });

        // 6. Product Lots approval status columns
        Schema::table('product_lots', function (Blueprint $table) {
            if (!Schema::hasColumn('product_lots', 'approval_status')) {
                $table->string('approval_status', 50)->default('approved')->after('lot_status');
            }
            if (!Schema::hasColumn('product_lots', 'approval_notes')) {
                $table->text('approval_notes')->nullable()->after('approval_status');
            }
            if (!Schema::hasColumn('product_lots', 'approved_by')) {
                $table->unsignedInteger('approved_by')->nullable()->after('approval_notes');
            }
        });

        // 7. Categories HS code and duty columns
        Schema::table('categories', function (Blueprint $table) {
            if (!Schema::hasColumn('categories', 'hs_code')) {
                $table->string('hs_code', 50)->nullable()->after('name');
            }
            if (!Schema::hasColumn('categories', 'standard_duty_rate')) {
                $table->decimal('standard_duty_rate', 8, 2)->nullable()->after('hs_code');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_dispute_messages');
        Schema::dropIfExists('b2b_disputes');
        Schema::dropIfExists('b2b_hs_codes');
        Schema::dropIfExists('b2b_audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['admin_role', 'admin_permissions']);
        });

        Schema::table('product_lots', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'approval_notes', 'approved_by']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['hs_code', 'standard_duty_rate']);
        });
    }
};

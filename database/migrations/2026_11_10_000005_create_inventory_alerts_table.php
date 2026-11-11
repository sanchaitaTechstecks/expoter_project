<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_alerts')) {
            Schema::create('inventory_alerts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('lot_id')->index();
                $table->unsignedInteger('supplier_id')->index();
                $table->string('alert_type', 50)->default('low_stock'); // low_stock, expiring_soon, expired
                $table->string('severity', 20)->default('warning');     // warning, critical
                $table->decimal('quantity_available', 15, 3)->nullable();
                $table->decimal('threshold', 15, 3)->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('message', 500)->nullable();
                $table->string('status', 20)->default('open');          // open, acknowledged, resolved
                $table->timestamp('acknowledged_at')->nullable();
                $table->unsignedInteger('acknowledged_by')->nullable();
                $table->timestamps();

                $table->unique(['lot_id', 'alert_type', 'status'], 'inventory_alerts_open_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
    }
};

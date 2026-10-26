<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'prescription_upload_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('prescription_upload_id')->nullable()->after('additional_info');
            });
        }

        if (!Schema::hasColumn('orders', 'prescription_note')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->text('prescription_note')->nullable()->after('prescription_upload_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['prescription_upload_id', 'prescription_note']);
        });
    }
};

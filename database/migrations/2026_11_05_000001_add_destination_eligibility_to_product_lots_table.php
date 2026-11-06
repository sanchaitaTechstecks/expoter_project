<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_lots') || Schema::hasColumn('product_lots', 'destination_eligibility')) {
            return;
        }

        Schema::table('product_lots', function (Blueprint $table) {
            // JSON array of destination countries the lot may be exported to.
            // NULL / empty array means "no destination restriction" (eligible anywhere).
            $table->json('destination_eligibility')->nullable()->after('quality_grade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_lots') || !Schema::hasColumn('product_lots', 'destination_eligibility')) {
            return;
        }

        Schema::table('product_lots', function (Blueprint $table) {
            $table->dropColumn('destination_eligibility');
        });
    }
};

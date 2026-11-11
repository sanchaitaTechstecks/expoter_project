<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rfqs')) {
            return;
        }

        Schema::table('rfqs', function (Blueprint $table) {
            if (!Schema::hasColumn('rfqs', 'dec013_export_offers')) {
                $table->json('dec013_export_offers')->nullable();
            }
            if (!Schema::hasColumn('rfqs', 'dec013_external_id')) {
                $table->string('dec013_external_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rfqs')) {
            return;
        }

        Schema::table('rfqs', function (Blueprint $table) {
            if (Schema::hasColumn('rfqs', 'dec013_export_offers')) {
                $table->dropColumn('dec013_export_offers');
            }
            if (Schema::hasColumn('rfqs', 'dec013_external_id')) {
                $table->dropColumn('dec013_external_id');
            }
        });
    }
};

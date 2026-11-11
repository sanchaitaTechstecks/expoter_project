<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'archived_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
                $table->string('archive_reason', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'archived_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['archived_at', 'archive_reason']);
            });
        }
    }
};

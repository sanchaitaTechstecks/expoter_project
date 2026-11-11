<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONVERSIONS = [
        ['kg', 'g', 1000, 'weight'],
        ['g', 'kg', 0.001, 'weight'],
        ['mt', 'kg', 1000, 'weight'],
        ['kg', 'mt', 0.001, 'weight'],
        ['lb', 'kg', 0.45359237, 'weight'],
        ['kg', 'lb', 2.20462262, 'weight'],
        ['quintal', 'kg', 100, 'weight'],
        ['kg', 'quintal', 0.01, 'weight'],
        ['l', 'ml', 1000, 'volume'],
        ['ml', 'l', 0.001, 'volume'],
        ['m3', 'l', 1000, 'volume'],
        ['dozen', 'pcs', 12, 'count'],
        ['pcs', 'dozen', 0.08333333, 'count'],
        ['ft', 'm', 0.3048, 'length'],
        ['m', 'ft', 3.2808399, 'length'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('b2b_unit_conversions')) {
            return;
        }

        $now = now();
        foreach (self::CONVERSIONS as [$from, $to, $factor, $category]) {
            $exists = DB::table('b2b_unit_conversions')
                ->where('from_unit', $from)
                ->where('to_unit', $to)
                ->exists();

            if (!$exists) {
                DB::table('b2b_unit_conversions')->insert([
                    'from_unit' => $from,
                    'to_unit' => $to,
                    'factor' => $factor,
                    'category' => $category,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('b2b_unit_conversions')) {
            return;
        }

        foreach (self::CONVERSIONS as [$from, $to]) {
            DB::table('b2b_unit_conversions')
                ->where('from_unit', $from)
                ->where('to_unit', $to)
                ->delete();
        }
    }
};

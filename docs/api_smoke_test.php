<?php

/**
 * Live HTTP smoke test for the B2B API (P2-1 .. P2-4).
 * Usage: php docs/api_smoke_test.php [baseUrl]
 * Prints one line per endpoint: PASS/FAIL status METHOD path -> key result
 * Creates one demo lot, two net-zero movements, one acknowledged alert and one completed
 * warehouse transfer so inventory endpoints return real numbers.
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$fail = 0;

function call(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode((string) $raw, true) ?? ['raw' => $raw]];
}

function dig(array $json, string $path)
{
    $value = $json;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return $value;
}

function show(string $method, string $path, array $res, string $pick = 'message'): void
{
    global $fail;
    [$status, $json] = $res;
    $ok = $status >= 200 && $status < 300;
    if (!$ok) {
        $fail++;
    }
    $value = dig($json, $pick);
    printf(
        "%s %-3d %-6s %-46s %s\n",
        $ok ? 'PASS' : 'FAIL',
        $status,
        $method,
        mb_strimwidth($path, 0, 46),
        is_scalar($value) ? mb_substr((string) $value, 0, 60) : json_encode($value)
    );
}

echo "Base URL: {$base}\n\n";

// ---------- Auth ----------
[, $impJson] = $r = call('POST', '/api/v1/importer/login', ['email' => 'buy_6af4402f8d4a4@example.com', 'password' => 'password']);
show('POST', '/api/v1/importer/login', $r, 'data.user.user_type');
$impToken = $impJson['data']['token'] ?? null;

[, $expJson] = $r = call('POST', '/api/v1/exporter/login', ['email' => 'sup_6af4402f88d49@example.com', 'password' => 'password']);
show('POST', '/api/v1/exporter/login', $r, 'data.user.user_type');
$expToken = $expJson['data']['token'] ?? null;

// ---------- Importer ----------
echo "\n--- Importer portal ---\n";
show('GET', '/api/v1/importer/dashboard', call('GET', '/api/v1/importer/dashboard', null, $impToken));
show('GET', '/api/v1/importer/products?per_page=2', call('GET', '/api/v1/importer/products?per_page=2', null, $impToken), 'data.0.name');
show('GET', '/api/v1/importer/products?origin_country=India&per_page=2', call('GET', '/api/v1/importer/products?origin_country=India&per_page=2', null, $impToken));
show('GET', '/api/v1/importer/products/1', call('GET', '/api/v1/importer/products/1', null, $impToken), 'data.name');
show('GET', '/api/v1/importer/categories', call('GET', '/api/v1/importer/categories', null, $impToken));
show('GET', '/api/v1/importer/rfqs', call('GET', '/api/v1/importer/rfqs', null, $impToken));
show('GET', '/api/v1/importer/quotations', call('GET', '/api/v1/importer/quotations', null, $impToken));
show('GET', '/api/v1/importer/orders', call('GET', '/api/v1/importer/orders', null, $impToken));
show('GET', '/api/v1/importer/shipments', call('GET', '/api/v1/importer/shipments', null, $impToken));
show('GET', '/api/v1/importer/documents', call('GET', '/api/v1/importer/documents', null, $impToken));
show('GET', '/api/v1/importer/payments', call('GET', '/api/v1/importer/payments', null, $impToken));
show('GET', '/api/v1/importer/disputes', call('GET', '/api/v1/importer/disputes', null, $impToken));

// ---------- Exporter ----------
echo "\n--- Exporter portal ---\n";
show('GET', '/api/v1/exporter/dashboard', call('GET', '/api/v1/exporter/dashboard', null, $expToken));
show('GET', '/api/v1/exporter/products', call('GET', '/api/v1/exporter/products', null, $expToken));
show('GET', '/api/v1/exporter/rfqs', call('GET', '/api/v1/exporter/rfqs', null, $expToken));
show('GET', '/api/v1/exporter/orders', call('GET', '/api/v1/exporter/orders', null, $expToken));

$products = call('GET', '/api/v1/exporter/products', null, $expToken)[1];
$productId = dig($products, 'data.data.0.id') ?? dig($products, 'data.0.id');

if (!$productId) {
    echo "No exporter product available to attach a lot to; skipping inventory demo.\n";
} else {
    $lotCode = 'SMOKE-' . date('YmdHis');
    $lotRes = call('POST', '/api/v1/exporter/lots', [
        'product_id' => $productId,
        'warehouse_location' => 'Warehouse A',
        'quality_grade' => 'A',
        'quantity' => 500,
        'unit' => 'kg',
        'unit_price' => 12.5,
        'currency' => 'USD',
        'manufacture_or_harvest_date' => date('Y-m-d', strtotime('-40 days')),
        'expiry_date' => date('Y-m-d', strtotime('+20 days')),
        'lot_code' => $lotCode,
    ], $expToken);
    show('POST', '/api/v1/exporter/lots', $lotRes, 'message');
    $lotId = dig($lotRes[1], 'data.id');

    show('GET', "/api/v1/exporter/products/{$productId}/lots", call('GET', "/api/v1/exporter/products/{$productId}/lots", null, $expToken));

    if ($lotId) {
        show('GET', "/api/v1/exporter/lots/{$lotId}", call('GET', "/api/v1/exporter/lots/{$lotId}", null, $expToken), 'data.lot_code');
        show('POST', "/api/v1/exporter/lots/{$lotId}/low-stock-threshold", call('POST', "/api/v1/exporter/lots/{$lotId}/low-stock-threshold", ['low_stock_threshold' => 600], $expToken), 'data.low_stock_threshold');
        show('POST', "/api/v1/exporter/lots/{$lotId}/movements (damaged)", call('POST', "/api/v1/exporter/lots/{$lotId}/movements", [
            'movement_type' => 'damaged', 'quantity' => 20, 'reason' => 'Smoke test: bags torn in transit',
        ], $expToken), 'data.lot.quantity_available');
        show('POST', "/api/v1/exporter/lots/{$lotId}/movements (returned)", call('POST', "/api/v1/exporter/lots/{$lotId}/movements", [
            'movement_type' => 'returned', 'quantity' => 5, 'reason' => 'Smoke test: buyer return',
        ], $expToken), 'data.lot.quantity_available');
        show('GET', "/api/v1/exporter/lots/{$lotId}/movements", call('GET', "/api/v1/exporter/lots/{$lotId}/movements", null, $expToken));
    }

    show('GET', '/api/v1/exporter/inventory/summary', call('GET', '/api/v1/exporter/inventory/summary', null, $expToken), 'data.total_available');
    show('GET', '/api/v1/exporter/inventory/low-stock', call('GET', '/api/v1/exporter/inventory/low-stock', null, $expToken), 'data.count');
    show('GET', '/api/v1/exporter/inventory/aging', call('GET', '/api/v1/exporter/inventory/aging', null, $expToken), 'data.summary.critical_30d');
    show('GET', '/api/v1/exporter/inventory/movements?per_page=5', call('GET', '/api/v1/exporter/inventory/movements?per_page=5', null, $expToken), 'data.movements.total');

    $scan = call('POST', '/api/v1/exporter/inventory/alerts/scan', [], $expToken);
    show('POST', '/api/v1/exporter/inventory/alerts/scan', $scan, 'data.open_total');
    $alerts = call('GET', '/api/v1/exporter/inventory/alerts', null, $expToken);
    show('GET', '/api/v1/exporter/inventory/alerts', $alerts, 'data.count');
    $alertId = dig($alerts[1], 'data.alerts.0.id');
    if ($alertId) {
        show('POST', "/api/v1/exporter/inventory/alerts/{$alertId}/acknowledge", call('POST', "/api/v1/exporter/inventory/alerts/{$alertId}/acknowledge", [], $expToken), 'data.status');
    }

    $transfer = call('POST', '/api/v1/exporter/inventory/transfers', [
        'from_warehouse' => 'Warehouse A',
        'to_warehouse' => 'Warehouse B',
        'notes' => 'Smoke test transfer',
        'items' => [['lot_id' => $lotId, 'quantity' => 100]],
    ], $expToken);
    show('POST', '/api/v1/exporter/inventory/transfers', $transfer, 'data.status');
    $transferId = dig($transfer[1], 'data.id');
    if ($transferId) {
        show('POST', "/api/v1/exporter/inventory/transfers/{$transferId}/complete", call('POST', "/api/v1/exporter/inventory/transfers/{$transferId}/complete", [], $expToken), 'data.status');
    }
    show('GET', '/api/v1/exporter/inventory/transfers', call('GET', '/api/v1/exporter/inventory/transfers', null, $expToken));
}

show('GET', '/api/v1/exporter/units/conversions', call('GET', '/api/v1/exporter/units/conversions', null, $expToken), 'data.0.from_unit');
show('POST', '/api/v1/exporter/units/convert', call('POST', '/api/v1/exporter/units/convert', ['from_unit' => 'kg', 'to_unit' => 'mt', 'quantity' => 1500], $expToken), 'data.converted');

// ---------- CHA (token from a real CHA login; password not seeded here) ----------
echo "\n--- CHA portal ---\n";
[, $chaJson] = $r = call('POST', '/api/v1/cha/login', ['email' => 'cha_6af4402fd29f2@customsbroker.com', 'password' => 'password']);
if (($chaJson['data']['token'] ?? null)) {
    $chaToken = $chaJson['data']['token'];
    show('POST', '/api/v1/cha/login', $r, 'data.user.user_type');
    show('GET', '/api/v1/cha/profile', call('GET', '/api/v1/cha/profile', null, $chaToken), 'data.user_type');
    show('GET', '/api/v1/cha/shipments', call('GET', '/api/v1/cha/shipments', null, $chaToken));
} else {
    echo "SKIP CHA login: the seeded CHA demo account password is unknown here; CHA auth/filing is covered by B2bChaPortalTest.\n";
}

echo "\n" . ($fail === 0 ? "All smoke checks passed.\n" : "{$fail} smoke check(s) failed.\n");
exit($fail === 0 ? 0 : 1);

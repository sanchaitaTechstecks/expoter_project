<?php

namespace Tests\Feature;

use App\Models\B2B\B2bOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** P2-8 auth hardening, P2-9 dashboards + 14 reports, P2-10 buyer/supplier disputes. */
class B2bP8ToP10AuthDashboardsDisputesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['url']->forceRootUrl('http://localhost');
        Storage::fake('public');
        config(['b2b.session_timeout_minutes' => 60]);
    }

    private function user(string $type, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($type) . ' ' . uniqid(),
            'email' => $type . '_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'user_type' => $type,
        ], $overrides));
    }

    private function order(User $buyer, User $seller): B2bOrder
    {
        return B2bOrder::create([
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'supplier_id' => $seller->id,
            'order_date' => now()->toDateString(),
            'order_type' => 'international',
            'status' => 'confirmed',
            'currency' => 'USD',
            'incoterm' => 'CIF',
            'total_quantity' => 100,
            'subtotal' => 120000,
            'total_amount' => 120000,
            'amount_paid' => 0,
            'balance_due' => 120000,
            'payment_status' => 'unpaid',
            'destination_country' => 'UAE',
        ]);
    }

    /** RFC 6238 TOTP code for the secret returned by two-factor/setup. */
    private function totp(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }

        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }

        $counter = (int) floor(time() / 30);
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), $key, true);
        $offset = ord($hash[19]) & 0xf;
        $value = (((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------ P2-8

    public function test_registration_email_verification_and_resend(): void
    {
        $email = 'exporter_' . uniqid() . '@example.com';

        $register = $this->postJson('/api/v1/exporter/register', [
            'name' => 'New Exporter', 'email' => $email,
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'phone' => '+919876543210', 'company_name' => 'New Exports Ltd',
            'country' => 'India', 'iec_code' => 'IEC' . rand(100000, 999999),
            'trade_type' => 'international',
        ]);

        $register->assertStatus(201);
        $register->assertJsonPath('data.email_verified', false);
        // Registrations must not be auto-trusted any more.
        $this->assertNull(User::where('email', $email)->first()->email_verified_at);

        $token = $register->json('data.verification_token');
        $this->assertNotEmpty($token, 'testing environment should expose the verification token');

        $this->postJson('/api/v1/exporter/email/verify', ['token' => $token])
            ->assertStatus(200)
            ->assertJsonPath('data.email_verified', true);

        $this->assertNotNull(User::where('email', $email)->first()->email_verified_at);

        // A consumed token cannot be replayed.
        $this->postJson('/api/v1/exporter/email/verify', ['token' => $token])->assertStatus(400);
        $this->postJson('/api/v1/exporter/email/verify', [])->assertStatus(422);

        // Already-verified users cannot ask for another link.
        $user = User::where('email', $email)->first();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/exporter/email/resend')->assertStatus(409);
    }

    public function test_password_forgot_and_reset_flow(): void
    {
        $user = $this->user('seller');

        $this->postJson('/api/v1/exporter/password/forgot', ['email' => $user->email])
            ->assertStatus(200)
            ->assertJsonPath('data.sent', true);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        // Unknown addresses are not disclosed.
        $this->postJson('/api/v1/exporter/password/forgot', ['email' => 'nobody_' . uniqid() . '@example.com'])
            ->assertStatus(200);

        $resetToken = Password::broker()->createToken($user);

        $this->postJson('/api/v1/exporter/password/reset', [
            'email' => $user->email,
            'token' => 'not-a-real-token',
            'password' => 'brandNewPass1',
            'password_confirmation' => 'brandNewPass1',
        ])->assertStatus(422);

        $this->postJson('/api/v1/exporter/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'brandNewPass1',
            'password_confirmation' => 'brandNewPass1',
        ])->assertStatus(200)->assertJsonPath('data.reset', true);

        $this->postJson('/api/v1/exporter/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertStatus(401);
        $this->postJson('/api/v1/exporter/login', ['email' => $user->email, 'password' => 'brandNewPass1'])
            ->assertStatus(200);

        $this->postJson('/api/v1/exporter/password/reset', [
            'email' => $user->email, 'token' => $resetToken, 'password' => 'short',
        ])->assertStatus(422);
    }

    public function test_two_factor_authentication_lifecycle(): void
    {
        $user = $this->user('seller');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/exporter/two-factor/status')
            ->assertStatus(200)->assertJsonPath('data.enabled', false);

        $setup = $this->postJson('/api/v1/exporter/two-factor/setup');
        $setup->assertStatus(200);
        $secret = $setup->json('data.secret');
        $this->assertNotEmpty($secret);
        $this->assertStringStartsWith('otpauth://totp/', (string) $setup->json('data.otpauth_url'));

        $this->postJson('/api/v1/exporter/two-factor/enable', ['code' => '000000'])
            ->assertStatus(422);

        $this->postJson('/api/v1/exporter/two-factor/enable', ['code' => $this->totp($secret)])
            ->assertStatus(200)->assertJsonPath('data.enabled', true);

        $this->getJson('/api/v1/exporter/two-factor/status')
            ->assertStatus(200)->assertJsonPath('data.enabled', true);

        // Login now demands the second factor.
        $noCode = $this->postJson('/api/v1/exporter/login', [
            'email' => $user->email, 'password' => 'password123',
        ]);
        $noCode->assertStatus(401);
        $noCode->assertJsonPath('errors.two_factor_required', true);

        $this->postJson('/api/v1/exporter/login', [
            'email' => $user->email, 'password' => 'password123', 'two_factor_code' => '111111',
        ])->assertStatus(401);

        $this->postJson('/api/v1/exporter/login', [
            'email' => $user->email, 'password' => 'password123', 'two_factor_code' => $this->totp($secret),
        ])->assertStatus(200)->assertJsonPath('data.two_factor_enabled', true);

        // Setup is refused while enabled; disabling needs the password.
        $this->postJson('/api/v1/exporter/two-factor/setup')->assertStatus(409);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/exporter/two-factor/disable', ['password' => 'wrong-pass'])
            ->assertStatus(422);
        $this->postJson('/api/v1/exporter/two-factor/disable', ['password' => 'password123'])
            ->assertStatus(200)->assertJsonPath('data.enabled', false);
    }

    public function test_configurable_session_timeout_and_banned_login(): void
    {
        $user = $this->user('seller');
        $plain = $user->createToken('session-test')->plainTextToken;

        $this->getJson('/api/v1/exporter/profile', ['Authorization' => "Bearer {$plain}"])
            ->assertStatus(200);

        // 90 idle minutes exceed the configured 60-minute timeout.
        $this->travel(90)->minutes();

        $expired = $this->getJson('/api/v1/exporter/profile', ['Authorization' => "Bearer {$plain}"]);
        $expired->assertStatus(401);
        $expired->assertJsonPath('errors.reason', 'session_timeout');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->travelBack();

        // With a 240-minute timeout the same idle age is still valid.
        config(['b2b.session_timeout_minutes' => 240]);
        $fresh = $user->createToken('session-test-2')->plainTextToken;
        $this->getJson('/api/v1/exporter/profile', ['Authorization' => "Bearer {$fresh}"])->assertStatus(200);
        $this->travel(90)->minutes();
        $this->getJson('/api/v1/exporter/profile', ['Authorization' => "Bearer {$fresh}"])->assertStatus(200);
        $this->travelBack();

        // Banned accounts cannot log in.
        $banned = $this->user('seller', ['banned' => 1]);
        $this->postJson('/api/v1/exporter/login', ['email' => $banned->email, 'password' => 'password123'])
            ->assertStatus(403);
    }

    // ------------------------------------------------------------ P2-9

    public function test_role_dashboards_and_fourteen_reports_with_exports(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        $admin = $this->user('admin');
        $this->order($buyer, $seller);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/importer/dashboard')
            ->assertStatus(200)->assertJsonPath('data.portal', 'importer');
        $this->getJson('/api/v1/importer/dashboard/summary')
            ->assertStatus(200)->assertJsonPath('data.portal', 'importer');

        Sanctum::actingAs($seller);
        $this->getJson('/api/v1/exporter/dashboard')
            ->assertStatus(200)->assertJsonPath('data.portal', 'exporter');

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/dashboard')
            ->assertStatus(200)->assertJsonPath('data.portal', 'admin');

        // A buyer may not open the back-office dashboard.
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/importer/dashboard')->assertStatus(200);

        // All 14 reports are catalogued and each one builds for the seller.
        Sanctum::actingAs($seller);
        $catalogue = $this->getJson('/api/v1/exporter/reports');
        $catalogue->assertStatus(200);
        $this->assertCount(14, $catalogue->json('data.reports'));

        $keys = array_column($catalogue->json('data.reports'), 'key');
        foreach ($keys as $key) {
            $report = $this->getJson("/api/v1/exporter/reports/{$key}");
            $report->assertStatus(200);
            $this->assertArrayHasKey('columns', $report->json('data'), "report {$key} must expose columns");
            $this->assertArrayHasKey('rows', $report->json('data'), "report {$key} must expose rows");
        }

        $this->getJson('/api/v1/exporter/reports/not_a_report')->assertStatus(404);
        $this->getJson('/api/v1/exporter/reports/sales_summary?from=2026-01-31&to=2026-01-01')
            ->assertStatus(422);

        // CSV / XLSX / PDF exports.
        $csv = $this->get('/api/v1/exporter/reports/sales_summary/export?format=csv');
        $csv->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));

        $xlsx = $this->get('/api/v1/exporter/reports/sales_summary/export?format=xlsx');
        $xlsx->assertStatus(200);
        $this->assertStringContainsString('spreadsheet', strtolower((string) $xlsx->headers->get('content-type')));

        $pdf = $this->get('/api/v1/exporter/reports/sales_summary/export?format=pdf');
        $pdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));

        $this->get('/api/v1/exporter/reports/sales_summary/export?format=xml')->assertStatus(422);

        // Report data is scoped: a second seller sees no orders of the first one.
        $otherSeller = $this->user('seller');
        Sanctum::actingAs($otherSeller);
        $other = $this->getJson('/api/v1/exporter/reports/sales_summary?from=' . now()->subDays(30)->toDateString()
            . '&to=' . now()->toDateString());
        $other->assertStatus(200);
        $this->assertSame(0, count($other->json('data.rows')));

        // Importer + admin report portals answer too.
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/importer/reports')->assertStatus(200);
        $this->getJson('/api/v1/importer/reports/purchase_summary')->assertStatus(200);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/reports')->assertStatus(200);
        $this->getJson('/api/v1/admin/reports/dispute_summary')->assertStatus(200);
    }

    // ----------------------------------------------------------- P2-10

    public function test_buyer_and_supplier_dispute_workflow(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        $order = $this->order($buyer, $seller);

        Sanctum::actingAs($buyer);

        $created = $this->postJson('/api/v1/importer/disputes', [
            'order_id' => $order->id,
            'dispute_type' => 'short_quantity',
            'title' => 'Only 87 kg of 100 kg delivered',
            'description' => 'The delivered consignment is short by 13 kg against the packing list.',
            'claim_amount' => 1560,
            'currency' => 'USD',
        ]);
        $created->assertStatus(201);
        $created->assertJsonPath('data.status', 'opened');
        $created->assertJsonPath('data.raised_by_id', $buyer->id);
        $disputeId = $created->json('data.id');

        // Duplicate open disputes of the same type are refused.
        $this->postJson('/api/v1/importer/disputes', [
            'order_id' => $order->id, 'dispute_type' => 'short_quantity',
            'title' => 'Duplicate', 'description' => 'Duplicate claim',
        ])->assertStatus(409);

        // Another buyer cannot dispute this order or read the case.
        $stranger = $this->user('customer');
        Sanctum::actingAs($stranger);
        $this->postJson('/api/v1/importer/disputes', [
            'order_id' => $order->id, 'dispute_type' => 'damaged_goods',
            'title' => 'Not mine', 'description' => 'Not my order',
        ])->assertStatus(404);
        $this->getJson("/api/v1/importer/disputes/{$disputeId}")->assertStatus(404);

        // Buyer conversation.
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/importer/disputes')->assertStatus(200);
        $this->postJson("/api/v1/importer/disputes/{$disputeId}/messages", [
            'message' => 'Attaching the weighbridge slip for reference.',
        ])->assertStatus(201);

        // Supplier sees the claim and replies.
        Sanctum::actingAs($seller);
        $inbox = $this->getJson('/api/v1/exporter/disputes');
        $inbox->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $inbox->json('data.total'));

        $this->getJson("/api/v1/exporter/disputes/{$disputeId}")->assertStatus(200);
        $this->postJson("/api/v1/exporter/disputes/{$disputeId}/messages", [
            'message' => 'We are checking the loading sheet and will revert today.',
        ])->assertStatus(201);

        // A different supplier cannot see or reply to the case.
        $otherSeller = $this->user('seller');
        Sanctum::actingAs($otherSeller);
        $this->getJson("/api/v1/exporter/disputes/{$disputeId}")->assertStatus(404);
        $this->postJson("/api/v1/exporter/disputes/{$disputeId}/reply", [
            'message' => 'Not my dispute',
        ])->assertStatus(404);

        // Validation and status transitions.
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/importer/disputes', ['title' => 'Missing fields'])->assertStatus(422);
        $this->assertSame('opened', DB::table('b2b_disputes')->where('id', $disputeId)->value('status'));
    }
}

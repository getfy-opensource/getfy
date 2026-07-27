<?php

namespace Tests\Feature;

use App\Gateways\Contracts\GatewayDriver;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReconcilePendingPaymentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeGatewayDriver::$statusCallCount = 0;
        FakeGatewayDriver::$returnStatus = 'paid';
        config(['payment_reconciliation.pix_max_age_minutes' => 0]);

        GatewayRegistry::register([
            'slug' => 'fake',
            'name' => 'Fake',
            'image' => '',
            'methods' => ['pix', 'boleto', 'card'],
            'scope' => 'national',
            'signup_url' => '',
            'driver' => FakeGatewayDriver::class,
            'credential_keys' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reconcile_pending_approves_pix_order_when_gateway_reports_paid(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-06-07 12:00:00');

        $order = $this->createPendingOrder([
            'checkout_payment_method' => 'pix',
            'created_at' => now()->subMinutes(2),
        ]);

        $callsBefore = FakeGatewayDriver::$statusCallCount;

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 10,
            '--days' => 30,
        ]);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertGreaterThanOrEqual(1, FakeGatewayDriver::$statusCallCount - $callsBefore);
    }

    public function test_pix_order_younger_than_first_interval_is_not_checked(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-06-07 12:00:00');

        $this->createPendingOrder([
            'checkout_payment_method' => 'pix',
            'created_at' => now()->subSeconds(30),
        ]);

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 10,
            '--days' => 30,
        ]);

        $this->assertSame(0, FakeGatewayDriver::$statusCallCount);
    }

    public function test_pix_order_skips_when_last_check_was_too_recent(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-06-07 12:00:00');

        $this->createPendingOrder([
            'checkout_payment_method' => 'pix',
            'created_at' => now()->subMinutes(5),
            'reconcile_last_checked_at' => now()->subSeconds(30)->toIso8601String(),
        ]);

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 10,
            '--days' => 30,
        ]);

        $this->assertSame(0, FakeGatewayDriver::$statusCallCount);
    }

    public function test_pix_order_in_second_tier_is_checked_after_five_minutes(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-06-07 12:00:00');

        $order = $this->createPendingOrder([
            'checkout_payment_method' => 'pix',
            'created_at' => now()->subMinutes(12),
            'reconcile_last_checked_at' => now()->subMinutes(6)->toIso8601String(),
        ]);

        $callsBefore = FakeGatewayDriver::$statusCallCount;

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 10,
            '--days' => 30,
        ]);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertGreaterThanOrEqual(1, FakeGatewayDriver::$statusCallCount - $callsBefore);
    }

    public function test_pix_order_older_than_120_minutes_stays_pending_and_still_reconciles(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-06-07 12:00:00');
        FakeGatewayDriver::$returnStatus = 'pending';

        $order = $this->createPendingOrder([
            'checkout_payment_method' => 'pix',
            'created_at' => now()->subMinutes(121),
        ]);

        $callsBefore = FakeGatewayDriver::$statusCallCount;

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 10,
            '--days' => 30,
        ]);

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->metadata['cancelled_reason'] ?? null);
        $this->assertGreaterThanOrEqual(1, FakeGatewayDriver::$statusCallCount - $callsBefore);
        $this->assertNotEmpty($order->metadata['reconcile_last_checked_at'] ?? null);
    }

    public function test_pix_order_expires_when_max_age_config_is_enabled(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-06-07 12:00:00');
        config(['payment_reconciliation.pix_max_age_minutes' => 120]);

        $order = $this->createPendingOrder([
            'checkout_payment_method' => 'pix',
            'created_at' => now()->subMinutes(121),
        ]);

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 10,
            '--days' => 30,
        ]);

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('reconcile_pix_expired', $order->metadata['cancelled_reason'] ?? null);
        $this->assertSame(0, FakeGatewayDriver::$statusCallCount);
    }

    public function test_boleto_order_skips_before_legacy_interval(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-06-07 12:00:00');

        $this->createPendingOrder([
            'checkout_payment_method' => 'boleto',
            'created_at' => now()->subMinute(),
        ]);

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 10,
            '--days' => 30,
        ]);

        $this->assertSame(0, FakeGatewayDriver::$statusCallCount);
    }

    public function test_boleto_order_is_checked_after_legacy_interval(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-06-07 12:00:00');

        $order = $this->createPendingOrder([
            'checkout_payment_method' => 'boleto',
            'created_at' => now()->subMinutes(3),
        ]);

        $callsBefore = FakeGatewayDriver::$statusCallCount;

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 10,
            '--days' => 30,
        ]);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertGreaterThanOrEqual(1, FakeGatewayDriver::$statusCallCount - $callsBefore);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPendingOrder(array $overrides = []): Order
    {
        $user = User::factory()->create();

        $productId = DB::table('products')->insertGetId([
            'tenant_id' => null,
            'name' => 'Produto Teste',
            'slug' => 'produto-teste-'.uniqid(),
            'description' => 'x',
            'type' => Product::TYPE_LINK_PAGAMENTO,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 10.00,
            'currency' => 'BRL',
            'is_active' => true,
            'checkout_slug' => 'abcdefg'.substr(uniqid(), -4),
            'checkout_config' => json_encode(Product::defaultCheckoutConfig(), JSON_UNESCAPED_UNICODE),
            'conversion_pixels' => json_encode(Product::defaultConversionPixels(), JSON_UNESCAPED_UNICODE),
            'member_area_config' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $metadata = [];
        if (isset($overrides['checkout_payment_method'])) {
            $metadata['checkout_payment_method'] = $overrides['checkout_payment_method'];
        }
        if (isset($overrides['reconcile_last_checked_at'])) {
            $metadata['reconcile_last_checked_at'] = $overrides['reconcile_last_checked_at'];
        }

        $createdAt = $overrides['created_at'] ?? now();

        $order = Order::create([
            'tenant_id' => null,
            'user_id' => $user->id,
            'product_id' => $productId,
            'status' => 'pending',
            'amount' => 10.00,
            'email' => $user->email,
            'gateway' => 'fake',
            'gateway_id' => 'tx_1',
            'metadata' => $metadata,
        ]);

        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $credential = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'fake',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['k' => 'v']);
        $credential->save();

        return $order;
    }
}

class FakeGatewayDriver implements GatewayDriver
{
    public static int $statusCallCount = 0;

    public static string $returnStatus = 'paid';

    public function testConnection(array $credentials): bool
    {
        return true;
    }

    public function createPixPayment(array $credentials, float $amount, array $consumer, string $externalId, string $postbackUrl): array
    {
        return ['transaction_id' => 'tx_1'];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        self::$statusCallCount++;

        return self::$returnStatus;
    }

    public function createCardPayment(array $credentials, float $amount, array $consumer, string $externalId, array $card): array
    {
        return ['transaction_id' => 'tx_1', 'status' => 'paid'];
    }

    public function createBoletoPayment(array $credentials, float $amount, array $consumer, string $externalId, string $notificationUrl): array
    {
        return [
            'transaction_id' => 'tx_1',
            'amount' => $amount,
            'expire_at' => now()->addDays(3)->toDateString(),
            'barcode' => '123',
            'pdf_url' => 'https://example.com/boleto.pdf',
        ];
    }
}

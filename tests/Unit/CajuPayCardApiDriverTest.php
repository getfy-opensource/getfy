<?php

namespace Tests\Unit;

use App\Gateways\CajuPay\CajuPayDriver;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CajuPayCardApiDriverTest extends TestCase
{
    /**
     * @return array{public_key: string, secret_key: string}
     */
    private function credentials(): array
    {
        return [
            'public_key' => 'gpk_test_public',
            'secret_key' => 'gsk_test_secret',
        ];
    }

    #[Test]
    public function create_card_charge_posts_to_v1_card(): void
    {
        Http::fake([
            '*/v1/card/charges' => Http::response([
                'id' => 'charge-1',
                'status' => 'requires_payment_method',
                'form_access_token' => 'fat_test',
            ], 201),
        ]);

        $driver = new CajuPayDriver;
        $result = $driver->createCardCharge($this->credentials(), [
            'amount' => 9900,
            'currency' => 'BRL',
            'payment_type' => 'credit',
            'customer' => ['name' => 'Cliente', 'email' => 'a@b.com', 'document' => '11144477735'],
        ], 'idem-create-1');

        $this->assertSame('charge-1', $result['id']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/card/charges')
                && $request->method() === 'POST'
                && ($request->header('Idempotency-Key')[0] ?? '') === 'idem-create-1';
        });
    }

    #[Test]
    public function create_card_charge_falls_back_to_api_card_on_404(): void
    {
        Http::fake([
            '*/v1/card/charges' => Http::response(['error' => 'not_found'], 404),
            '*/api/card/charges' => Http::response([
                'id' => 'charge-api',
                'status' => 'requires_payment_method',
            ], 201),
        ]);

        $driver = new CajuPayDriver;
        $result = $driver->createCardCharge($this->credentials(), [
            'amount' => 2500,
            'currency' => 'BRL',
            'payment_type' => 'credit',
        ], 'idem-fallback');

        $this->assertSame('charge-api', $result['id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/card/charges'));
    }

    #[Test]
    public function confirm_and_get_card_charge(): void
    {
        Http::fake([
            '*/v1/card/charges/charge-1/confirm' => Http::response([
                'id' => 'charge-1',
                'status' => 'succeeded',
                'card_token' => 'tok_saved_1',
            ], 200),
            '*/v1/card/charges/charge-1' => Http::response([
                'id' => 'charge-1',
                'status' => 'succeeded',
            ], 200),
        ]);

        $driver = new CajuPayDriver;
        $confirmed = $driver->confirmCardCharge($this->credentials(), 'charge-1', [
            'payment_token' => 'ptok_1',
            'save_card' => true,
            'installments' => 1,
        ]);
        $this->assertSame('succeeded', $confirmed['status']);
        $this->assertSame('tok_saved_1', $confirmed['card_token']);

        $got = $driver->getCardCharge($this->credentials(), 'charge-1');
        $this->assertSame('charge-1', $got['id']);
    }

    #[Test]
    public function get_installment_options_and_charge_with_token(): void
    {
        Http::fake([
            '*/v1/card/installment-options*' => Http::response([
                'installment_options' => [
                    ['installments' => 1, 'installment_amount_cents' => 9900],
                    ['installments' => 2, 'installment_amount_cents' => 4950],
                ],
            ], 200),
            '*/v1/card/charges' => Http::response([
                'id' => 'charge-token',
                'status' => 'succeeded',
            ], 201),
        ]);

        $driver = new CajuPayDriver;
        $opts = $driver->getCardInstallmentOptions($this->credentials(), 9900, 6);
        $this->assertCount(2, $opts['options']);

        $charged = $driver->createCardChargeWithToken(
            $this->credentials(),
            9900,
            'BRL',
            'tok_saved_1',
            ['name' => 'Cliente', 'email' => 'a@b.com', 'document' => '11144477735'],
            'idem-token-1'
        );
        $this->assertSame('charge-token', $charged['id']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v1/card/charges') || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return ($body['card_token'] ?? null) === 'tok_saved_1'
                && (int) ($body['amount'] ?? 0) === 9900;
        });
    }
}

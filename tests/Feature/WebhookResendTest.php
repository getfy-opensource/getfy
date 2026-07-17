<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookResendTest extends TestCase
{
    public function test_owner_can_resend_a_logged_webhook_with_the_original_payload(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        Http::fake(['hooks.example.com/*' => Http::response('received', 200)]);

        $user = User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $webhook = Webhook::create([
            'tenant_id' => 1,
            'name' => 'CRM',
            'url' => 'https://hooks.example.com/orders',
            'bearer_token' => 'secret-token',
            'events' => [OrderCompleted::class],
            'is_active' => true,
        ]);
        $payload = [
            'event' => 'pedido_pago',
            'event_label' => 'Pedido pago',
            'payload' => ['order' => ['id' => 123]],
            'timestamp' => '2026-04-22T01:00:00-03:00',
        ];
        $originalLog = WebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => 'pedido_pago',
            'event_label' => 'Pedido pago',
            'request_payload' => $payload,
            'response_status' => 500,
            'response_body' => 'error',
            'success' => false,
            'error_message' => 'HTTP 500',
            'source' => 'job',
        ]);

        $this->actingAs($user)
            ->postJson(route('integrations.webhooks.logs.resend', [$webhook, $originalLog]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Webhook reenviado com sucesso.');

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://hooks.example.com/orders'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request->data() === $payload
        );

        $resendLog = WebhookLog::query()
            ->where('webhook_id', $webhook->id)
            ->where('source', 'resend')
            ->first();

        $this->assertNotNull($resendLog);
        $this->assertTrue($resendLog->success);
        $this->assertSame(200, $resendLog->response_status);
        $this->assertSame($payload, $resendLog->request_payload);
    }

    public function test_user_cannot_resend_another_tenants_webhook_log(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);
        Http::fake();

        $owner = User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $otherUser = User::factory()->create([
            'tenant_id' => 2,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $webhook = Webhook::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'CRM',
            'url' => 'https://hooks.example.com/orders',
            'events' => [OrderCompleted::class],
            'is_active' => true,
        ]);
        $log = WebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => 'pedido_pago',
            'event_label' => 'Pedido pago',
            'request_payload' => ['event' => 'pedido_pago'],
            'success' => false,
            'source' => 'job',
        ]);

        $this->actingAs($otherUser)
            ->postJson(route('integrations.webhooks.logs.resend', [$webhook, $log]))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(1, WebhookLog::query()->count());
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Models\User;
use App\Services\PanelPushService;
use App\Support\VapidKeysManager;
use GuzzleHttp\Psr7\Request;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Mockery;
use Tests\TestCase;

/**
 * Botão "Enviar notificação de teste" da central de notificações: o push vai só para os dispositivos
 * do próprio usuário (nunca para colegas do tenant nem outros tenants) e não entra no histórico.
 */
class PanelPushTestNotificationTest extends TestCase
{
    /** @var list<string> */
    private array $sentEndpoints = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);

        $keys = VAPID::createVapidKeys();
        $vapid = Mockery::mock(VapidKeysManager::class);
        $vapid->shouldReceive('resolveKeyPair')->andReturn([
            'public' => $keys['publicKey'],
            'private' => $keys['privateKey'],
        ]);
        $this->app->instance(VapidKeysManager::class, $vapid);

        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('sendOneNotification')->andReturnUsing(function (Subscription $sub, ?string $payload) {
            $this->sentEndpoints[] = $sub->getEndpoint();
            $this->assertStringContainsString('teste', strtolower((string) json_decode((string) $payload, true)['title']));

            return new MessageSentReport(new Request('POST', $sub->getEndpoint()), null, true, 'OK');
        });

        $service = Mockery::mock(PanelPushService::class)->makePartial();
        $service->shouldReceive('makeWebPush')->andReturn($webPush);
        $this->app->instance(PanelPushService::class, $service);
    }

    private function subscribe(User $user, string $endpoint): void
    {
        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'endpoint' => $endpoint,
            'keys' => ['auth' => 'auth-key', 'p256dh' => 'p256dh-key'],
            'preferences' => ['pix' => false, 'boleto' => false, 'card' => false],
        ]);
    }

    public function test_sends_only_to_current_user_devices(): void
    {
        $me = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $colleague = User::factory()->create(['role' => User::ROLE_ADMIN, 'tenant_id' => 1]);
        $otherTenant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 2]);

        $this->subscribe($me, 'https://push.example.com/me-phone');
        $this->subscribe($me, 'https://push.example.com/me-laptop');
        $this->subscribe($colleague, 'https://push.example.com/colleague');
        $this->subscribe($otherTenant, 'https://push.example.com/other-tenant');

        $this->actingAs($me)
            ->postJson('/painel/push-test')
            ->assertOk()
            ->assertJson(['success' => true, 'sent' => 2, 'subscriptions' => 2]);

        sort($this->sentEndpoints);
        $this->assertSame(['https://push.example.com/me-laptop', 'https://push.example.com/me-phone'], $this->sentEndpoints);
        $this->assertSame(0, PanelNotification::count(), 'push de teste não deve poluir o histórico');
    }

    public function test_returns_422_when_user_has_no_device_subscribed(): void
    {
        $me = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $colleague = User::factory()->create(['role' => User::ROLE_ADMIN, 'tenant_id' => 1]);
        $this->subscribe($colleague, 'https://push.example.com/colleague');

        $this->actingAs($me)
            ->postJson('/painel/push-test')
            ->assertStatus(422)
            ->assertJson(['success' => false, 'sent' => 0, 'subscriptions' => 0]);

        $this->assertSame([], $this->sentEndpoints);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/painel/push-test')->assertUnauthorized();
        $this->assertSame([], $this->sentEndpoints);
    }
}

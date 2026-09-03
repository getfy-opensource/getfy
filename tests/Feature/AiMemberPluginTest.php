<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\PluginClassAutoloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\AiMember\Models\AiMemberAgent;
use Plugins\AiMember\Models\AiMemberConnection;
use Plugins\AiMember\Models\AiMemberConversation;
use Plugins\AiMember\Models\AiMemberMessage;
use Tests\TestCase;

class AiMemberPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_dir(base_path('plugins/ai-member'))) {
            $this->markTestSkipped('ai-member plugin ausente.');
        }
    }

    public function test_validate_command_passes(): void
    {
        $this->artisan('plugin:validate', ['slug' => 'ai-member'])
            ->assertSuccessful();
    }

    public function test_connection_endpoint_requires_auth(): void
    {
        $this->getJson('/ai-member/connection')->assertUnauthorized();
    }

    public function test_connection_crud_for_tenant(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $this->actingAs($user);

        $this->putJson('/ai-member/connection', [
            'api_key' => 'sk-or-test-key',
            'is_active' => true,
        ])->assertOk()->assertJsonPath('connection.configured', true);

        $this->getJson('/ai-member/connection')
            ->assertOk()
            ->assertJsonPath('connection.has_token', true)
            ->assertJsonPath('connection.is_active', true);
    }

    public function test_agent_settings_for_product(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => $user->tenant_id,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);

        $this->actingAs($user);

        $this->getJson("/ai-member/agents/{$product->id}")
            ->assertOk()
            ->assertJsonStructure(['agent', 'documents', 'openrouter_configured']);

        $this->putJson("/ai-member/agents/{$product->id}", [
            'enabled' => true,
            'name' => 'Ana',
            'gender' => 'female',
            'welcome_message' => 'Olá!',
        ])->assertOk()->assertJsonPath('agent.name', 'Ana');
    }

    public function test_chat_requires_member_access(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $student = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => $owner->tenant_id]);
        $product = $this->createTestProduct([
            'tenant_id' => $owner->tenant_id,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);

        AiMemberConnection::query()->create([
            'tenant_id' => $owner->tenant_id,
            'api_key' => 'sk-or-test',
            'is_active' => true,
        ]);

        AiMemberAgent::query()->create([
            'product_id' => $product->id,
            'tenant_id' => $owner->tenant_id,
            'enabled' => true,
            'name' => 'Bot',
        ]);

        $this->actingAs($student);

        $this->postJson('/api/ai-member/chat', [
            'product_id' => $product->id,
            'message' => 'oi',
        ])->assertStatus(422);
    }

    public function test_integration_status_resolver(): void
    {
        PluginClassAutoloader::refreshPrefixes();
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        AiMemberConnection::query()->create([
            'tenant_id' => $user->tenant_id,
            'api_key' => 'sk-or-test',
            'is_active' => true,
        ]);

        $register = require base_path('plugins/ai-member/bootstrap.php');
        if (is_callable($register)) {
            $register(app(), app('events'));
        }

        $ext = \App\Plugins\PluginExtensionRegistry::getBootstrapExtension('ai-member');
        $this->assertIsCallable($ext['integration_status_resolver'] ?? null);
        $this->assertTrue($ext['integration_status_resolver']($user->tenant_id));
    }

    public function test_member_builder_tab_is_registered_when_plugin_enabled(): void
    {
        Plugin::query()->create([
            'slug' => 'ai-member',
            'name' => 'AI Member',
            'version' => '1.0.0',
            'is_enabled' => true,
        ]);

        $tabs = \App\Plugins\PluginExtensionRegistry::getMemberBuilderTabs();
        $this->assertNotEmpty($tabs);
        $this->assertSame('ai-member', $tabs[0]['id'] ?? null);
        $this->assertSame('AI Member', $tabs[0]['label'] ?? null);
        $this->assertSame('runtime', $tabs[0]['ui_mode'] ?? null);
        $this->assertSame('ProductAgentPanel', $tabs[0]['ui_export'] ?? null);
    }

    public function test_widget_icon_upload_and_remove(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => $user->tenant_id,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);

        $this->actingAs($user);

        $file = \Illuminate\Http\UploadedFile::fake()->image('icon.png', 128, 128);

        $this->postJson("/ai-member/agents/{$product->id}/widget-icon", [
            'file' => $file,
        ])->assertOk()->assertJsonStructure(['url', 'agent']);

        $agent = AiMemberAgent::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($agent?->widget_icon);

        $this->deleteJson("/ai-member/agents/{$product->id}/widget-icon")
            ->assertOk()
            ->assertJsonPath('agent.widget_icon', null);
    }

    public function test_member_area_inertia_includes_enabled_ai_member_widget(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\EnsureInstalled::class);

        Plugin::query()->create([
            'slug' => 'ai-member',
            'name' => 'AI Member',
            'version' => '1.0.0',
            'is_enabled' => true,
        ]);

        $register = require base_path('plugins/ai-member/bootstrap.php');
        if (is_callable($register)) {
            $register(app(), app('events'));
        }

        $slug = 'ai'.substr(md5(uniqid('', true)), 0, 6);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $student = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $product->users()->attach($student->id);

        AiMemberConnection::query()->create([
            'tenant_id' => 1,
            'api_key' => 'sk-or-test',
            'is_active' => true,
        ]);

        AiMemberAgent::query()->create([
            'product_id' => $product->id,
            'tenant_id' => 1,
            'enabled' => true,
            'name' => 'Assistente',
        ]);

        $this->actingAs($student)
            ->get('/m/'.$slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MemberAreaApp/Show')
                ->where('ai_member_widget.enabled', true)
                ->where('ai_member_widget.product_id', $product->id)
                ->where('ai_member_widget.name', 'Assistente')
            );
    }

    public function test_widget_uses_theme_primary_color_when_configured(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\EnsureInstalled::class);

        Plugin::query()->create([
            'slug' => 'ai-member',
            'name' => 'AI Member',
            'version' => '1.0.0',
            'is_enabled' => true,
        ]);

        $register = require base_path('plugins/ai-member/bootstrap.php');
        if (is_callable($register)) {
            $register(app(), app('events'));
        }

        $slug = 'ai'.substr(md5(uniqid('', true)), 0, 6);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
            'member_area_config' => [
                'theme' => ['primary' => '#ff5500'],
            ],
        ]);

        $student = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $product->users()->attach($student->id);

        AiMemberConnection::query()->create([
            'tenant_id' => 1,
            'api_key' => 'sk-or-test',
            'is_active' => true,
        ]);

        AiMemberAgent::query()->create([
            'product_id' => $product->id,
            'tenant_id' => 1,
            'enabled' => true,
            'name' => 'Assistente',
            'widget_color_source' => 'theme',
            'widget_color' => '#6366f1',
        ]);

        $this->actingAs($student)
            ->get('/m/'.$slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_member_widget.widget_color', '#ff5500')
                ->where('ai_member_widget.widget_color_source', 'theme')
            );
    }

    public function test_conversations_list_and_new_conversation(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $student = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1, 'name' => 'Leonardo']);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);
        $product->users()->attach($student->id);

        AiMemberConnection::query()->create([
            'tenant_id' => 1,
            'api_key' => 'sk-or-test',
            'is_active' => true,
        ]);

        AiMemberAgent::query()->create([
            'product_id' => $product->id,
            'tenant_id' => 1,
            'enabled' => true,
            'name' => 'Tutor',
        ]);

        $conversation = AiMemberConversation::query()->create([
            'product_id' => $product->id,
            'user_id' => $student->id,
            'title' => 'Primeira dúvida',
            'last_message_at' => now()->subDay(),
        ]);

        AiMemberMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'oi',
        ]);

        $this->actingAs($student);

        $this->getJson('/api/ai-member/conversations?product_id='.$product->id)
            ->assertOk()
            ->assertJsonPath('conversations.0.title', 'Primeira dúvida');

        $this->postJson('/api/ai-member/conversations/new', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonStructure(['conversation_id']);

        $conversation->refresh();
        $this->assertNotNull($conversation->archived_at);

        $this->getJson('/api/ai-member/history?product_id='.$product->id)
            ->assertOk()
            ->assertJsonPath('messages', []);
    }
}

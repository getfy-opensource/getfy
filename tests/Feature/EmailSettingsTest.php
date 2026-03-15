<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    public function test_email_test_endpoint_returns_success()
    {
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);

        // Prepare some settings (global)
        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('mail_from_address', 'noreply@example.com', null);
        Setting::set('mail_from_name', 'Example', null);

        Mail::fake();

        $response = $this->actingAs($user)->postJson('/configuracoes/email/test', [
            'test_to' => 'test@example.com',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_email_send_test_endpoint_returns_success()
    {
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('mail_from_address', 'noreply@example.com', null);
        Setting::set('mail_from_name', 'Example', null);

        Mail::fake();

        $response = $this->actingAs($user)->postJson('/configuracoes/email/send-test', [
            'test_to' => 'test@example.com',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }
}


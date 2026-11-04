<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    public function test_login_page_uses_platform_branding_and_legal_routes()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $this->assertPlatformAuthBranding($response->getContent());
    }

    public function test_forgot_password_page_uses_platform_branding()
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
        $this->assertPlatformAuthBranding($response->getContent());
    }

    public function test_password_reset_page_uses_platform_branding()
    {
        $response = $this->get(route('password.reset', ['token' => 'local-test-token']));

        $response->assertOk();
        $this->assertPlatformAuthBranding($response->getContent());
    }

    public function test_existing_login_flow_still_authenticates_a_user()
    {
        $user = User::create([
            'name' => 'Local Auth Check',
            'email' => 'auth-check@example.test',
            'username' => 'auth-check@example.test',
            'code' => 'AUTH-CHECK-USER',
            'user_type' => 'super_admin',
            'password' => Hash::make('LocalAuthCheck!'),
            'school_id' => null,
        ]);

        $this->post(route('login'), [
            'identity' => 'auth-check@example.test',
            'password' => 'LocalAuthCheck!',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    private function assertPlatformAuthBranding($html)
    {
        $this->assertStringContainsString('<h4 class="text-bold text-white">NexaSMS by Mathias</h4>', $html);
        $visibleText = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('NexaSMS by Mathias. All rights reserved.', $visibleText);
        $this->assertStringContainsString('/privacy-policy"', $html);
        $this->assertStringContainsString('/terms-of-use"', $html);
        $this->assertStringNotContainsString('CJ Inspired', $html);
        $this->assertStringNotContainsString('Local Smoke School A', $html);
    }
}

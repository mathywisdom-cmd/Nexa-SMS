<?php

namespace Tests\Feature;

use App\Models\School;
use App\Notifications\NexaSmsPasswordReset;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetEmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $this->app['config']->set('app.url', 'https://nexasms.test');
        $this->app['config']->set('tenancy.base_domain', 'nexasms.test');
    }

    public function test_reset_notification_uses_nexasms_branding_and_an_app_url_based_token_link()
    {
        $user = $this->makeUser('platform-user@reset.test');
        $notification = new NexaSmsPasswordReset('valid-reset-token');
        $message = $notification->toMail($user);
        $expectedUrl = 'https://nexasms.test'.route('password.reset', [
            'token' => 'valid-reset-token',
            'email' => $user->email,
        ], false);

        $this->assertSame('Reset your NexaSMS password', $message->subject);
        $this->assertSame(60, $message->viewData['expireMinutes']);
        $this->assertSame($expectedUrl, $message->viewData['resetUrl']);
        $this->assertSame('https://nexasms.test'.route('privacy_policy', [], false), $message->viewData['privacyUrl']);
        $this->assertSame('https://nexasms.test'.route('terms_of_use', [], false), $message->viewData['termsUrl']);

        $html = view($message->view['html'], $message->viewData)->render();
        $text = view($message->view['text'], $message->viewData)->render();

        foreach (['NexaSMS by Mathias', 'Hello Reset Test User', 'Reset Password', $expectedUrl,
            'will expire in 60 minutes', 'Privacy Policy', 'Terms &amp; Conditions'] as $content) {
            $this->assertStringContainsString($content, $html);
        }
        foreach (['NexaSMS by Mathias', $expectedUrl, 'Privacy Policy:', 'Terms & Conditions:'] as $content) {
            $this->assertStringContainsString($content, $text);
        }
        foreach (['Laravel', 'Laravel Logo', 'CJ Inspired', 'Local Smoke School A', '127.0.0.1', 'localhost'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $html);
            $this->assertStringNotContainsString($legacy, $text);
        }
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_school_reset_link_uses_the_school_subdomain_from_existing_tenant_configuration()
    {
        $school = School::create(['name' => 'Reset Test School', 'code' => 'RESET-SCHOOL']);
        $user = $this->makeUser('school-user@reset.test', $school->id);

        $message = (new NexaSmsPasswordReset('school-reset-token'))->toMail($user);

        $this->assertStringStartsWith('https://reset-test-school.nexasms.test/password/reset/school-reset-token', $message->viewData['resetUrl']);
        $this->assertStringNotContainsString('Local Smoke School A', $message->viewData['resetUrl']);
    }

    public function test_local_reset_link_uses_the_configured_local_app_url_and_port()
    {
        $this->app['config']->set('app.url', 'http://127.0.0.1:8765');
        $school = School::create(['name' => 'Local Reset Test School', 'code' => 'LOCAL-RESET-SCHOOL']);
        $user = $this->makeUser('local-user@reset.test', $school->id);

        $message = (new NexaSmsPasswordReset('local-reset-token'))->toMail($user);

        $this->assertStringStartsWith('http://127.0.0.1:8765/password/reset/local-reset-token', $message->viewData['resetUrl']);
    }

    public function test_password_email_workflow_sends_the_custom_notification_without_changing_token_expiry()
    {
        Notification::fake();
        $user = $this->makeUser('workflow-user@reset.test');

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect();

        Notification::assertSentTo($user, NexaSmsPasswordReset::class, function ($notification) use ($user) {
            $message = $notification->toMail($user);
            $this->assertSame(60, $message->viewData['expireMinutes']);
            $this->assertStringContainsString('/password/reset/', $message->viewData['resetUrl']);
            $this->assertStringContainsString('email='.urlencode($user->email), $message->viewData['resetUrl']);
            return true;
        });

        $this->assertSame(1, DB::table('password_resets')->where('email', $user->email)->count());
    }

    private function makeUser($email, $schoolId = null)
    {
        return User::create([
            'name' => 'Reset Test User',
            'email' => $email,
            'username' => $email,
            'code' => strtoupper(str_replace(['@', '.', '-'], '', $email)),
            'user_type' => $schoolId ? 'admin' : 'super_admin',
            'password' => Hash::make('ResetTestPassword!'),
            'school_id' => $schoolId,
        ]);
    }
}

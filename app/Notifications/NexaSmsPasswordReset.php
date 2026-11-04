<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class NexaSmsPasswordReset extends ResetPassword
{
    public function toMail($notifiable)
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        $baseUrl = $this->baseUrlFor($notifiable);

        if (static::$createUrlCallback) {
            $resetUrl = call_user_func(static::$createUrlCallback, $notifiable, $this->token);
        } else {
            $resetPath = route('password.reset', [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false);

            $resetUrl = $baseUrl.'/'.ltrim($resetPath, '/');
        }

        return (new MailMessage)
            ->subject('Reset your NexaSMS password')
            ->view([
                'html' => 'emails.password-reset',
                'text' => 'emails.password-reset-text',
            ], [
                'userName' => $notifiable->name,
                'resetUrl' => $resetUrl,
                'expireMinutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
                'privacyUrl' => $baseUrl.'/'.ltrim(route('privacy_policy', [], false), '/'),
                'termsUrl' => $baseUrl.'/'.ltrim(route('terms_of_use', [], false), '/'),
            ]);
    }

    /**
     * Use APP_URL as the canonical origin, keeping a validated school host when
     * the request already arrived through that school's existing subdomain.
     */
    private function baseUrlFor($notifiable)
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $parts = parse_url($appUrl);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $appUrl;
        }

        $scheme = $parts['scheme'];
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $basePath = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

        if ($notifiable->school_id) {
            $school = $notifiable->school;
            if ($school && $school->slug) {
                $resolvedHost = app(\App\Tenancy\SchoolHostResolver::class)->resolve(request()->getHost());

                if (isset($resolvedHost['type'], $resolvedHost['school'])
                    && $resolvedHost['type'] === 'school'
                    && (int) $resolvedHost['school']->id === (int) $notifiable->school_id) {
                    return request()->getSchemeAndHttpHost().$basePath;
                }

                $baseDomain = strtolower(trim((string) config('tenancy.base_domain'), '.'));
                $usesTenantDomain = $baseDomain !== ''
                    && ($host === $baseDomain || substr($host, -strlen('.'.$baseDomain)) === '.'.$baseDomain);
                $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

                if ($usesTenantDomain && !$isLoopback) {
                    $host = $school->slug.'.'.$baseDomain;
                }
            }
        }

        return $scheme.'://'.$host.$port.$basePath;
    }
}

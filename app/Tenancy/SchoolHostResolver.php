<?php

namespace App\Tenancy;

use App\Models\School;

/** Maps an accepted platform or school hostname to the existing school record. */
class SchoolHostResolver
{
    public function resolve($host)
    {
        $host = strtolower(rtrim((string) $host, '.'));
        $baseDomain = strtolower(trim((string) config('tenancy.base_domain'), '.'));
        $localDomain = strtolower(trim((string) config('tenancy.local_domain', 'localhost'), '.'));

        if ($host === $baseDomain || $host === 'www.'.$baseDomain || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return ['type' => 'platform'];
        }

        if ($localDomain && app()->environment(['local', 'testing']) && $host === $localDomain) {
            return ['type' => 'local'];
        }

        if ($baseDomain && $this->isSubdomainOf($host, $baseDomain)) {
            return $this->schoolResult(substr($host, 0, -strlen('.'.$baseDomain)));
        }

        if ($localDomain && app()->environment(['local', 'testing']) && $this->isSubdomainOf($host, $localDomain)) {
            return $this->schoolResult(substr($host, 0, -strlen('.'.$localDomain)));
        }

        return ['type' => 'unknown'];
    }

    private function isSubdomainOf($host, $domain)
    {
        return strlen($host) > strlen($domain) && substr($host, -strlen('.'.$domain)) === '.'.$domain;
    }

    private function schoolResult($label)
    {
        // Accept one URL-safe label only; nested or malformed hostnames never
        // become a guessed school or a school ID.
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $label)) {
            return ['type' => 'unknown'];
        }

        $school = School::where('slug', $label)->first();

        return $school ? ['type' => 'school', 'school' => $school] : ['type' => 'unknown'];
    }
}

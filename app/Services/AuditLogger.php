<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function record($action, $model = null, $description = null, array $properties = [], $schoolId = null, $actorId = null)
    {
        $request = app()->bound('request') ? request() : null;
        $properties = $this->sanitize($properties);
        $actor = Auth::user();
        $resolvedSchoolId = $schoolId ?: app(TenantContext::class)->schoolId() ?: ($actor ? $actor->school_id : null);

        return AuditLog::create([
            'school_id' => $resolvedSchoolId,
            'actor_id' => $actorId ?: Auth::id(),
            'action' => $action,
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id' => $model && $model->getKey() ? $model->getKey() : null,
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => $request ? $request->ip() : null,
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    protected function sanitize(array $properties)
    {
        $sensitive = ['password', 'password_confirmation', 'remember_token', 'token', 'access_token', 'refresh_token', 'secret'];
        foreach ($properties as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                unset($properties[$key]);
            } elseif (is_array($value)) {
                $properties[$key] = $this->sanitize($value);
            }
        }
        return $properties;
    }
}

<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use App\Models\School;
use App\Tenancy\SchoolHostResolver;
use Closure;
use Illuminate\Support\Facades\Auth;

class InitializeTenantContext
{
    public function handle($request, Closure $next)
    {
        $tenant = app(TenantContext::class);
        $tenant->clear();

        $host = app(SchoolHostResolver::class)->resolve($request->getHost());
        if ($host['type'] === 'unknown') {
            abort(404);
        }

        $user = Auth::user();

        if ($host['type'] === 'school') {
            $school = $host['school'];

            if (!in_array($school->status, ['active', 'trial'], true)) {
                if ($user && $request->routeIs('logout') && (int) $user->school_id === (int) $school->id && $user->user_type !== 'super_admin') {
                    $tenant->setSchoolId($school->id);
                    return $next($request);
                }

                abort(403, 'This school account is not active.');
            }

            if ($user && ((int) $user->school_id !== (int) $school->id || $user->user_type === 'super_admin')) {
                abort(404);
            }

            $tenant->setSchoolId($school->id);
            return $next($request);
        }

        if ($host['type'] === 'platform' && $user && $user->school_id && $user->user_type !== 'super_admin') {
            // The configured platform domain is not a school tenant. Local
            // loopback hosts retain the existing user-school behavior below.
            if (!in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true)) {
                abort(404);
            }
        }

        if ($user) {
            if ($user->user_type === 'super_admin') {
                $tenant->allowPlatformAccess();
            } elseif (!$user->school_id) {
                abort(403, 'Your account is not assigned to a school.');
            } else {
                $school = School::find($user->school_id);
                if (!$school || !in_array($school->status, ['active', 'trial'], true)) {
                    if ($request->routeIs('logout')) {
                        $tenant->setSchoolId($user->school_id);
                        return $next($request);
                    }
                    abort(403, 'This school account is not active.');
                }
                $tenant->setSchoolId($user->school_id);
            }
        }

        return $next($request);
    }
}

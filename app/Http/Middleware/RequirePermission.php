<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Support\Facades\Auth;

class RequirePermission
{
    protected $permissions;

    public function __construct(PermissionService $permissions)
    {
        $this->permissions = $permissions;
    }

    public function handle($request, Closure $next, $permission)
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        abort_unless($this->permissions->userCan($permission), 403, 'You do not have permission to perform this action.');
        return $next($request);
    }
}

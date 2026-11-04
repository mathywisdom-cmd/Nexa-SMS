<?php

namespace App\Http\Middleware\Custom;

use Closure;
use App\Helpers\Qs;
use Illuminate\Support\Facades\Auth;
use App\Services\PermissionService;

class TeamSAT
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!Auth::check()) return redirect()->route('login');
        $permissions = app(PermissionService::class);
        foreach (['students.view', 'attendance.view', 'marks.view', 'exams.view', 'classes.view', 'subjects.view'] as $permission) {
            if ($permissions->userCan($permission)) return $next($request);
        }
        abort(403, 'You do not have permission to access this school module.');
    }
}

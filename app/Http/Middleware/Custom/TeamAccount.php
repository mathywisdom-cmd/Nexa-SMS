<?php

namespace App\Http\Middleware\Custom;

use Closure;
use App\Helpers\Qs;
use Illuminate\Support\Facades\Auth;
use App\Services\PermissionService;

class TeamAccount
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
        return Auth::check() && app(PermissionService::class)->userCan('fees.view')
            ? $next($request) : redirect()->route('login');
    }
}

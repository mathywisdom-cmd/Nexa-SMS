<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use App\Services\PlatformDashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, PlatformDashboardService $dashboard, PermissionService $permissions)
    {
        return view('pages.super_admin.dashboard', array_merge(
            $dashboard->data($request->query('period')),
            [
                'canManageSchools' => $permissions->userCan('platform.schools.manage'),
                'canViewAuditLogs' => $permissions->userCan('platform.audit_logs.view'),
                'canViewReports' => $permissions->userCan('platform.reports.view'),
            ]
        ));
    }
}

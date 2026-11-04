<?php

namespace Tests\Feature;

use App\Services\PermissionService;
use Tests\TestCase;

class PermissionBoundaryTest extends TestCase
{
    public function test_platform_users_cannot_receive_school_permissions()
    {
        $service = app(PermissionService::class);
        $owner = (object) ['user_type' => 'super_admin'];

        $this->assertFalse($service->allows($owner, 'students.manage'));
        $this->assertFalse($service->allows($owner, 'attendance.mark'));
    }

    public function test_school_users_cannot_receive_platform_permissions()
    {
        $service = app(PermissionService::class);
        $schoolAdmin = (object) ['user_type' => 'admin'];

        $this->assertFalse($service->allows($schoolAdmin, 'platform.schools.manage'));
        $this->assertFalse($service->allows($schoolAdmin, 'platform.audit_logs.view'));
    }
}

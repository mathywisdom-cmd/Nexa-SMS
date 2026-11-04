<?php

namespace Tests\Feature;

use App\Services\PermissionService;
use App\Tenancy\TenantContext;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_existing_role_permission_matrix_keeps_school_and_platform_boundaries()
    {
        $schoolId = $this->school('Role Matrix School');
        $service = app(PermissionService::class);
        $expected = [
            'admin' => ['students.view', 'marks.manage', 'fees.manage', 'settings.manage'],
            'principal' => ['students.view', 'marks.view', 'marks.manage', 'attendance.view', 'attendance.mark'],
            'head_teacher' => ['students.view', 'marks.view', 'marks.manage', 'attendance.view', 'attendance.mark'],
            'teacher' => ['students.view', 'marks.view', 'marks.manage', 'attendance.view', 'attendance.mark'],
            'accountant' => ['students.view', 'fees.view', 'fees.manage', 'payments.record'],
            'bursar' => ['students.view', 'fees.view', 'fees.manage', 'payments.record'],
            'librarian' => ['students.view', 'library.manage'],
            'staff' => ['attendance.view'],
        ];

        foreach ($expected as $role => $granted) {
            $user = $this->user($role, $schoolId, 'matrix-'.$role);
            foreach (['students.view', 'marks.view', 'marks.manage', 'fees.view', 'fees.manage', 'payments.record', 'library.manage', 'attendance.view', 'attendance.mark', 'settings.manage', 'platform.schools.manage'] as $permission) {
                $expectedPermission = strpos($permission, 'platform.') !== 0
                    && (in_array($permission, $granted, true) || $role === 'admin');
                $this->assertSame($expectedPermission, $service->allows($user, $permission), $role.' '.$permission);
            }
        }
    }

    public function test_platform_owner_is_schoolless_and_school_users_cannot_access_platform_routes()
    {
        $schoolId = $this->school('Platform Boundary School');
        app(TenantContext::class)->clear();
        $owner = User::create([
            'name' => 'Platform Owner', 'email' => 'owner@platform.test', 'username' => 'owner@platform.test',
            'code' => 'PLATFORM-OWNER', 'user_type' => 'super_admin',
            'password' => Hash::make('RoleMatrixTest!'), 'school_id' => null,
        ]);
        $admin = $this->user('admin', $schoolId, 'platform-boundary-admin');

        $this->assertNull(DB::table('users')->where('id', $owner->id)->value('school_id'));
        $this->actingAs($owner)->get('/super_admin')->assertOk();
        $this->actingAs($owner)->get('/super_admin/schools')->assertOk();
        $this->actingAs($admin)->get('/super_admin/schools')->assertRedirect('/login');
    }

    public function test_finance_roles_keep_finance_access_and_cannot_open_school_settings()
    {
        $schoolId = $this->school('Finance Role School');
        $accountant = $this->user('accountant', $schoolId, 'finance-accountant');
        $this->actingAs($accountant)->get('/payments')->assertOk();

        foreach (['accountant', 'bursar'] as $role) {
            $user = $role === 'accountant' ? $accountant : $this->user($role, $schoolId, 'finance-'.$role);
            $this->actingAs($user)->post('/payments/pay_now/999', ['amt_paid' => 1])->assertNotFound();
            $this->actingAs($user)->get('/school/settings')->assertForbidden();
        }

        $principal = $this->user('principal', $schoolId, 'finance-principal');
        $this->actingAs($principal)->get('/school/settings')->assertForbidden();
    }

    private function school($name)
    {
        return DB::table('schools')->insertGetId([
            'name' => $name, 'code' => strtoupper(str_replace(' ', '-', $name)), 'status' => 'active',
            'timezone' => 'UTC', 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user($role, $schoolId, $suffix)
    {
        app(TenantContext::class)->setSchoolId($schoolId);
        return User::create([
            'name' => ucfirst($role).' Role User', 'email' => $suffix.'@roles.test',
            'username' => $suffix.'@roles.test', 'code' => strtoupper('RM-'.$suffix),
            'user_type' => $role, 'password' => Hash::make('RoleMatrixTest!'), 'school_id' => $schoolId,
        ]);
    }
}

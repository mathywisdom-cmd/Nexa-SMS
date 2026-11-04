<?php

namespace Tests\Feature;

use App\Tenancy\TenantContext;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        $this->owner = $this->createUser('Platform Owner', 'super_admin', null);
    }

    public function test_platform_owner_can_access_platform_dashboard()
    {
        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('NexaSMS by Mathias')
            ->assertSee('Platform Health');
    }

    public function test_school_admin_cannot_access_platform_dashboard()
    {
        $school = $this->createSchool('Admin School', 'active');
        $this->actingAs($this->createUser('School Admin', 'admin', $school))
            ->get(route('owner.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_teacher_cannot_access_platform_dashboard()
    {
        $school = $this->createSchool('Teacher School', 'active');
        $this->actingAs($this->createUser('Teacher', 'teacher', $school))
            ->get(route('owner.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_parent_cannot_access_platform_dashboard()
    {
        $school = $this->createSchool('Parent School', 'active');
        $this->actingAs($this->createUser('Parent', 'parent', $school))
            ->get(route('owner.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_student_cannot_access_platform_dashboard()
    {
        $school = $this->createSchool('Student School', 'active');
        $this->actingAs($this->createUser('Student', 'student', $school))
            ->get(route('owner.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_school_totals_and_status_counts_use_database_values()
    {
        $defaultSchoolId = DB::table('schools')->where('code', 'DEFAULT')->value('id');
        DB::table('schools')->where('id', $defaultSchoolId)->update(['status' => 'active']);
        $this->createSchool('Active Test School', 'active');
        $this->createSchool('Inactive Test School', 'inactive');
        $this->createSchool('Suspended Test School', 'suspended');
        $this->createSchool('Trial Test School', 'trial');

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertViewHas('totalSchools', 5)
            ->assertViewHas('activeSchools', 2)
            ->assertViewHas('inactiveSchools', 1)
            ->assertViewHas('suspendedSchools', 1)
            ->assertViewHas('trialSchools', 1);
    }

    public function test_dashboard_counts_school_students_teachers_parents_and_staff_without_platform_owner()
    {
        $schoolA = $this->createSchool('Population School A', 'active');
        $schoolB = $this->createSchool('Population School B', 'active');
        $this->createUser('Student A1', 'student', $schoolA);
        $this->createUser('Student A2', 'student', $schoolA);
        $this->createUser('Student B1', 'student', $schoolB);
        $this->createUser('Teacher A', 'teacher', $schoolA);
        $this->createUser('Teacher B', 'teacher', $schoolB);
        $this->createUser('Parent A', 'parent', $schoolA);
        $this->createUser('Parent B', 'parent', $schoolB);
        $this->createUser('Accountant A', 'accountant', $schoolA);

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertViewHas('totalStudents', 3)
            ->assertViewHas('totalTeachers', 2)
            ->assertViewHas('totalParents', 2)
            ->assertViewHas('totalStaff', 3);
    }

    public function test_recent_registration_rows_show_counts_for_the_matching_school()
    {
        $schoolA = $this->createSchool('Recent School A', 'active');
        $schoolB = $this->createSchool('Recent School B', 'active');
        $this->createUser('Student A', 'student', $schoolA);
        $this->createUser('Teacher A', 'teacher', $schoolA);
        $this->createUser('Admin A', 'admin', $schoolA);
        $this->createUser('Student B', 'student', $schoolB);
        $this->createUser('Student B2', 'student', $schoolB);
        $this->createUser('Admin B', 'admin', $schoolB);

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertViewHas('recentSchools', function ($schools) use ($schoolA, $schoolB) {
                $a = $schools->firstWhere('id', $schoolA);
                $b = $schools->firstWhere('id', $schoolB);
                return $a && $b
                    && (int) $a->students_count === 1
                    && (int) $a->teachers_count === 1
                    && $a->administrator_name === 'Admin A'
                    && (int) $b->students_count === 2
                    && (int) $b->teachers_count === 0
                    && $b->administrator_name === 'Admin B';
            });
    }

    public function test_platform_dashboard_aggregates_across_schools_instead_of_using_tenant_scope()
    {
        $schoolA = $this->createSchool('Tenant Check A', 'active');
        $schoolB = $this->createSchool('Tenant Check B', 'active');
        $this->createUser('Student A', 'student', $schoolA);
        $this->createUser('Student B', 'student', $schoolB);
        app(TenantContext::class)->setSchoolId($schoolA);

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertViewHas('totalStudents', 2)
            ->assertViewHas('totalSchools', 3);
    }

    public function test_empty_activity_period_renders_empty_states_without_errors()
    {
        DB::table('schools')->where('code', 'DEFAULT')->update([
            'created_at' => now()->subDays(90),
            'updated_at' => now()->subDays(90),
        ]);

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard', ['period' => 'today']))
            ->assertOk()
            ->assertSee('No recent registrations for this period.')
            ->assertSee('No recent platform activity.')
            ->assertViewHas('recentSchools', function ($schools) { return $schools->isEmpty(); })
            ->assertViewHas('recentActivity', function ($activity) { return $activity->isEmpty(); });
    }

    public function test_date_filter_changes_activity_not_permanent_totals()
    {
        DB::table('schools')->where('code', 'DEFAULT')->update(['created_at' => now()->subDays(90)]);
        $oldSchool = $this->createSchool('Old School', 'active', now()->subDays(60));
        $recentSchool = $this->createSchool('Recent School', 'active');
        $this->createUser('Old Student', 'student', $oldSchool);
        $this->createUser('Recent Student', 'student', $recentSchool);

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard', ['period' => 'today']))
            ->assertOk()
            ->assertViewHas('totalSchools', 3)
            ->assertViewHas('totalStudents', 2)
            ->assertViewHas('recentRegistrationsCount', 1);
    }

    public function test_platform_alerts_are_based_on_real_school_status_and_administrator_records()
    {
        $this->createSchool('Suspended Alert School', 'suspended');
        $this->createSchool('Inactive Alert School', 'inactive');
        $withoutAdmin = $this->createSchool('No Admin Alert School', 'active');

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertViewHas('alerts', function ($alerts) use ($withoutAdmin) {
                $labels = collect($alerts)->pluck('label')->all();
                $adminAlert = collect($alerts)->firstWhere('label', 'Schools without an administrator');
                return in_array('Suspended schools', $labels, true)
                    && in_array('Inactive schools', $labels, true)
                    && $adminAlert
                    && $adminAlert['count'] >= 1
                    && collect($adminAlert['schools'])->contains('id', $withoutAdmin);
            });
    }

    public function test_quick_action_and_audit_link_visibility_respects_owner_permissions()
    {
        $auditPermission = DB::table('permissions')->where('name', 'platform.audit_logs.view')->value('id');
        DB::table('user_permissions')->insert([
            'user_id' => $this->owner->id,
            'permission_id' => $auditPermission,
            'allowed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertDontSee('View Audit Logs')
            ->assertDontSee('View All Audit Logs')
            ->assertSee('Add School')
            ->assertSee('Platform Profile');
    }

    protected function createSchool($name, $status, $createdAt = null)
    {
        return DB::table('schools')->insertGetId([
            'name' => $name,
            'code' => strtoupper(Str::random(12)),
            'status' => $status,
            'timezone' => 'UTC',
            'currency' => 'UGX',
            'created_at' => $createdAt ?: now(),
            'updated_at' => $createdAt ?: now(),
        ]);
    }

    protected function createUser($name, $type, $schoolId)
    {
        $email = Str::lower(Str::random(12)).'@example.test';
        $id = DB::table('users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'username' => $email,
            'code' => Str::random(32),
            'user_type' => $type,
            'school_id' => $schoolId,
            'password' => Hash::make('local-test-password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::withoutGlobalScope('school')->findOrFail($id);
    }
}

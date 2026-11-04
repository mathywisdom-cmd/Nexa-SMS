<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\School;
use App\Tenancy\TenantContext;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchoolSubdomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('tenancy.base_domain', 'nexasms.com');
        $this->app['config']->set('tenancy.local_domain', 'localhost');
        $this->app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_school_creation_generates_safe_unique_slugs()
    {
        $school = $this->school("St Mary's College!");
        $duplicate = $this->school("St Mary's College!");

        $this->assertSame('st-marys-college', $school->slug);
        $this->assertSame('st-marys-college-2', $duplicate->slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $school->slug);
    }

    public function test_existing_platform_school_creation_workflow_generates_a_slug()
    {
        $owner = $this->user('super_admin', null, 'school-create-owner');

        $this->actingAs($owner)->post('/super_admin/schools', [
            'name' => 'Green Hill School',
            'code' => 'GREEN-HILL',
            'timezone' => 'Africa/Kampala',
            'currency' => 'UGX',
            'status' => 'active',
            'admin_name' => 'Green Hill Admin',
            'admin_email' => 'green-hill-admin@subdomain.test',
            'admin_password' => 'SchoolAdminTest!23',
            'admin_password_confirmation' => 'SchoolAdminTest!23',
        ])->assertRedirect('/super_admin/schools');

        $this->assertSame('green-hill-school', DB::table('schools')->where('code', 'GREEN-HILL')->value('slug'));
    }

    public function test_valid_existing_slug_is_preserved()
    {
        $school = new School([
            'name' => 'Renamed School Display Name',
            'code' => 'PRESERVED-SLUG',
            'status' => 'active',
            'timezone' => 'UTC',
            'currency' => 'UGX',
        ]);
        $school->slug = 'original-school-url';
        $school->save();

        $this->assertSame('original-school-url', $school->fresh()->slug);
    }

    public function test_existing_default_school_receives_a_slug_from_the_additive_migration()
    {
        $this->assertSame('default-school', DB::table('schools')->where('code', 'DEFAULT')->value('slug'));
    }

    public function test_school_subdomain_resolves_and_sets_the_existing_tenant_context()
    {
        $school = $this->school('Kampala Parents School');

        $this->get('http://'.$school->slug.'.nexasms.com/login')
            ->assertOk();

        $this->assertSame($school->id, app(TenantContext::class)->schoolId());
        $this->assertFalse(app(TenantContext::class)->hasPlatformAccess());
    }

    public function test_main_platform_domain_does_not_resolve_a_school_and_keeps_super_admin_platform_level()
    {
        $owner = $this->user('super_admin', null, 'platform-owner');

        $this->actingAs($owner)->get('http://nexasms.com/login');

        $this->assertNull(app(TenantContext::class)->schoolId());
        $this->assertTrue(app(TenantContext::class)->hasPlatformAccess());
    }

    public function test_unknown_subdomain_returns_404_without_falling_back_to_a_school()
    {
        $this->get('http://does-not-exist.nexasms.com/login')
            ->assertNotFound();

        $this->assertNull(app(TenantContext::class)->schoolId());
    }

    public function test_inactive_school_subdomain_uses_existing_inactive_school_restriction()
    {
        $school = $this->school('Inactive School', 'suspended');

        $this->get('http://'.$school->slug.'.nexasms.com/login')
            ->assertForbidden();
    }

    public function test_user_can_still_log_out_from_an_inactive_school_subdomain()
    {
        $school = $this->school('Inactive Logout School', 'suspended');
        $user = $this->user('admin', $school->id, 'inactive-logout-admin');

        $this->actingAs($user)
            ->post('http://'.$school->slug.'.nexasms.com/logout')
            ->assertRedirect();
    }

    public function test_school_user_cannot_change_host_to_another_school()
    {
        $schoolA = $this->school('Host Isolation School A');
        $schoolB = $this->school('Host Isolation School B');
        $userA = $this->user('admin', $schoolA->id, 'host-admin-a');

        $this->actingAs($userA)->get('http://'.$schoolB->slug.'.nexasms.com/login')
            ->assertNotFound();
    }

    public function test_each_school_host_keeps_user_queries_scoped_to_its_own_school()
    {
        $schoolA = $this->school('Record Host School A');
        $schoolB = $this->school('Record Host School B');
        $userA = $this->user('admin', $schoolA->id, 'record-admin-a');
        $userB = $this->user('admin', $schoolB->id, 'record-admin-b');

        $this->actingAs($userA)->get('http://'.$schoolA->slug.'.nexasms.com/dashboard')->assertOk();
        $schoolAIds = User::query()->pluck('id')->all();
        $this->assertContains($userA->id, $schoolAIds);
        $this->assertNotContains($userB->id, $schoolAIds);

        $this->actingAs($userB)->get('http://'.$schoolB->slug.'.nexasms.com/dashboard')->assertOk();
        $schoolBIds = User::query()->pluck('id')->all();
        $this->assertContains($userB->id, $schoolBIds);
        $this->assertNotContains($userA->id, $schoolBIds);
    }

    public function test_school_subdomain_does_not_expose_foreign_student_record_ids()
    {
        $schoolA = $this->school('Direct ID School A');
        $schoolB = $this->school('Direct ID School B');
        [$classA, $sectionA] = $this->classAndSection($schoolA->id, 'Direct ID A');
        [$classB, $sectionB] = $this->classAndSection($schoolB->id, 'Direct ID B');
        $studentA = $this->user('student', $schoolA->id, 'direct-student-a');
        $studentB = $this->user('student', $schoolB->id, 'direct-student-b');
        $recordA = $this->studentRecord($studentA->id, $schoolA->id, $classA, $sectionA);
        $recordB = $this->studentRecord($studentB->id, $schoolB->id, $classB, $sectionB);
        $adminA = $this->user('admin', $schoolA->id, 'direct-admin-a');
        $adminB = $this->user('admin', $schoolB->id, 'direct-admin-b');

        $this->actingAs($adminA)
            ->get('http://'.$schoolA->slug.'.nexasms.com/students/'.Qs::hash($recordA))
            ->assertOk();
        $this->actingAs($adminA)
            ->get('http://'.$schoolA->slug.'.nexasms.com/students/'.Qs::hash($recordB))
            ->assertNotFound();

        $this->actingAs($adminB)
            ->get('http://'.$schoolB->slug.'.nexasms.com/students/'.Qs::hash($recordB))
            ->assertOk();
        $this->actingAs($adminB)
            ->get('http://'.$schoolB->slug.'.nexasms.com/students/'.Qs::hash($recordA))
            ->assertNotFound();
    }

    public function test_platform_owner_is_not_assigned_to_the_tenant_when_using_school_subdomain()
    {
        $school = $this->school('Owner Host Isolation School');
        $owner = $this->user('super_admin', null, 'host-platform-owner');

        $this->actingAs($owner)->get('http://'.$school->slug.'.nexasms.com/login')
            ->assertNotFound();
    }

    public function test_local_loopback_login_remains_available()
    {
        $this->get('http://127.0.0.1:8765/login')
            ->assertOk();
    }

    private function school($name, $status = 'active')
    {
        return School::create([
            'name' => $name,
            'code' => strtoupper(Str::random(10)),
            'status' => $status,
            'timezone' => 'UTC',
            'currency' => 'UGX',
        ]);
    }

    private function user($role, $schoolId, $suffix)
    {
        return User::create([
            'name' => $suffix,
            'email' => $suffix.'@subdomain.test',
            'username' => $suffix.'@subdomain.test',
            'code' => strtoupper($suffix),
            'user_type' => $role,
            'school_id' => $schoolId,
            'password' => Hash::make('SubdomainTest!'),
        ]);
    }

    private function classAndSection($schoolId, $prefix)
    {
        $type = DB::table('class_types')->insertGetId([
            'name' => $prefix.' Type', 'code' => strtoupper(Str::random(8)), 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $class = DB::table('my_classes')->insertGetId([
            'name' => $prefix.' Class', 'class_type_id' => $type, 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $section = DB::table('sections')->insertGetId([
            'name' => $prefix.' Section', 'my_class_id' => $class, 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$class, $section];
    }

    private function studentRecord($userId, $schoolId, $classId, $sectionId)
    {
        return DB::table('student_records')->insertGetId([
            'user_id' => $userId,
            'my_class_id' => $classId,
            'section_id' => $sectionId,
            'adm_no' => strtoupper('ADM-'.$userId),
            'session' => '2026-2027',
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

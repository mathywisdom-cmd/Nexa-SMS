<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Tenancy\TenantContext;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentProfileAuthorizationTest extends TestCase
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

    public function test_school_user_with_students_view_can_open_same_school_profile()
    {
        [$schoolId, $classId, $sectionId] = $this->schoolFixture('Profile Permission School');
        $student = $this->user('student', $schoolId, 'profile-student');
        $this->studentRecord($student, $schoolId, $classId, $sectionId);
        $accountant = $this->user('accountant', $schoolId, 'profile-accountant');

        $this->actingAs($accountant)->get('/students/'.Qs::hash(1))->assertOk();
    }

    public function test_school_user_without_students_view_cannot_open_student_profile()
    {
        [$schoolId, $classId, $sectionId] = $this->schoolFixture('Profile Denied School');
        $student = $this->user('student', $schoolId, 'denied-profile-student');
        $this->studentRecord($student, $schoolId, $classId, $sectionId);
        $staff = $this->user('staff', $schoolId, 'profile-staff');

        $this->actingAs($staff)->get('/students/'.Qs::hash(1))->assertForbidden();
    }

    public function test_parent_and_student_can_open_only_their_own_student_profile()
    {
        [$schoolId, $classId, $sectionId] = $this->schoolFixture('Profile Family School');
        $parentA = $this->user('parent', $schoolId, 'profile-parent-a');
        $parentB = $this->user('parent', $schoolId, 'profile-parent-b');
        $studentA = $this->user('student', $schoolId, 'profile-student-a');
        $studentB = $this->user('student', $schoolId, 'profile-student-b');
        $recordA = $this->studentRecord($studentA, $schoolId, $classId, $sectionId, $parentA->id);
        $recordB = $this->studentRecord($studentB, $schoolId, $classId, $sectionId, $parentB->id);

        $this->actingAs($parentA)->get('/students/'.Qs::hash($recordA))->assertOk();
        $this->actingAs($parentA)->get('/students/'.Qs::hash($recordB))->assertForbidden();
        $this->actingAs($studentA)->get('/students/'.Qs::hash($recordA))->assertOk();
        $this->actingAs($studentA)->get('/students/'.Qs::hash($recordB))->assertForbidden();
    }

    public function test_admin_and_assigned_teacher_can_open_profile_but_teacher_cannot_open_unassigned_student()
    {
        [$schoolId, $classId, $sectionId] = $this->schoolFixture('Profile Staff School');
        $studentA = $this->user('student', $schoolId, 'staff-student-a');
        $studentB = $this->user('student', $schoolId, 'staff-student-b');
        $recordA = $this->studentRecord($studentA, $schoolId, $classId, $sectionId);
        $otherSectionId = DB::table('sections')->insertGetId([
            'name' => 'Unassigned Section', 'my_class_id' => $classId, 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $recordB = $this->studentRecord($studentB, $schoolId, $classId, $otherSectionId);
        $teacher = $this->user('teacher', $schoolId, 'profile-teacher');
        DB::table('sections')->where('id', $sectionId)->update(['teacher_id' => $teacher->id]);
        $admin = $this->user('admin', $schoolId, 'profile-admin');

        $this->actingAs($admin)->get('/students/'.Qs::hash($recordA))->assertOk();
        $this->actingAs($teacher)->get('/students/'.Qs::hash($recordA))->assertOk();
        $this->actingAs($teacher)->get('/students/'.Qs::hash($recordB))->assertNotFound();
    }

    public function test_student_profile_lookup_is_scoped_to_the_authenticated_school()
    {
        [$schoolA, $classA, $sectionA] = $this->schoolFixture('Profile Tenant School A');
        [$schoolB, $classB, $sectionB] = $this->schoolFixture('Profile Tenant School B');
        $student = $this->user('student', $schoolB, 'foreign-profile-student');
        $record = $this->studentRecord($student, $schoolB, $classB, $sectionB);
        $adminA = $this->user('admin', $schoolA, 'profile-admin-a');

        $this->actingAs($adminA)->get('/students/'.Qs::hash($record))->assertNotFound();
    }

    private function schoolFixture($name)
    {
        $schoolId = DB::table('schools')->insertGetId([
            'name' => $name, 'code' => strtoupper(str_replace(' ', '-', $name)), 'status' => 'active',
            'timezone' => 'UTC', 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $typeId = DB::table('class_types')->insertGetId([
            'name' => $name.' Type', 'code' => strtoupper(substr(md5($name), 0, 8)), 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $classId = DB::table('my_classes')->insertGetId([
            'name' => $name.' Class', 'class_type_id' => $typeId, 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sectionId = DB::table('sections')->insertGetId([
            'name' => $name.' Section', 'my_class_id' => $classId, 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$schoolId, $classId, $sectionId];
    }

    private function user($role, $schoolId, $suffix)
    {
        app(TenantContext::class)->setSchoolId($schoolId);

        return User::create([
            'name' => ucfirst($role).' Profile User', 'email' => $suffix.'@profile.test',
            'username' => $suffix.'@profile.test', 'code' => strtoupper('SP-'.$suffix),
            'user_type' => $role, 'password' => Hash::make('StudentProfileTest!'), 'school_id' => $schoolId,
        ]);
    }

    private function studentRecord($student, $schoolId, $classId, $sectionId, $parentId = null)
    {
        return DB::table('student_records')->insertGetId([
            'user_id' => $student->id, 'my_parent_id' => $parentId, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'adm_no' => strtoupper('ADM-'.$student->id), 'session' => '2026-2027',
            'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

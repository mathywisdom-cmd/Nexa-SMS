<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Tenancy\TenantContext;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortalOwnershipTest extends TestCase
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

    public function test_parent_children_and_student_attendance_routes_are_limited_to_their_children()
    {
        [$schoolA, $classA, $sectionA] = $this->schoolFixture('Portal School A');
        [$schoolB, $classB, $sectionB] = $this->schoolFixture('Portal School B');
        $parentA = $this->user('parent', $schoolA, 'portal-parent-a');
        $parentB = $this->user('parent', $schoolA, 'portal-parent-b');
        $childA = $this->user('student', $schoolA, 'portal-child-a');
        $childB = $this->user('student', $schoolA, 'portal-child-b');
        $foreignChild = $this->user('student', $schoolB, 'portal-foreign-child');
        $recordA = $this->studentRecord($childA, $schoolA, $classA, $sectionA, $parentA->id);
        $this->addResult($childA, $schoolA, $classA, $sectionA);
        $this->studentRecord($childB, $schoolA, $classA, $sectionA, $parentB->id);
        $foreignRecord = $this->studentRecord($foreignChild, $schoolB, $classB, $sectionB, $parentA->id);

        $this->actingAs($parentA)->get('/dashboard')->assertOk();
        $this->actingAs($parentA)->get('/my_children')->assertOk()->assertSee($childA->name)->assertDontSee($childB->name);
        $this->actingAs($childA)->get('/attendance/student/'.$childA->id)->assertOk();
        $this->actingAs($parentA)->get('/attendance/student/'.$childA->id)->assertOk();
        $this->actingAs($parentA)->get('/attendance/student/'.$childB->id)->assertNotFound();
        $this->actingAs($parentA)->get('/attendance/student/'.$foreignChild->id)->assertNotFound();
        $this->actingAs($parentA)->get('/students/'.Qs::hash($foreignRecord))->assertNotFound();

        $this->actingAs($parentA)->get('/marks/show/'.Qs::hash($childB->id).'/2026-2027')->assertRedirect('/dashboard');
        $this->actingAs($parentA)->get('/marks/show/'.Qs::hash($foreignChild->id).'/2026-2027')->assertRedirect('/dashboard');
        $this->actingAs($parentA)->get('/marks/select_year/'.Qs::hash($childA->id))->assertOk();
        $this->actingAs($parentA)->post('/marks/select_year/'.Qs::hash($childA->id), ['year' => '2026-2027'])
            ->assertRedirect(route('marks.show', [Qs::hash($childA->id), '2026-2027']));
        $ownParentResult = $this->actingAs($parentA)->get('/marks/show/'.Qs::hash($childA->id).'/2026-2027');
        $this->assertSame(200, $ownParentResult->status(), $ownParentResult->getContent());
        $this->actingAs($parentA)->put('/marks/update/1/1/1/1', [])->assertForbidden();
        $this->actingAs($parentA)->post('/attendance', [])->assertForbidden();
        $this->actingAs($parentA)->get('/payments/invoice/'.$childA->id)->assertRedirect('/login');
        $this->assertNotNull($recordA);
    }

    public function test_student_cannot_view_or_change_another_students_profile_or_results()
    {
        [$schoolId, $classId, $sectionId] = $this->schoolFixture('Student Portal School');
        $studentA = $this->user('student', $schoolId, 'student-portal-a');
        $studentB = $this->user('student', $schoolId, 'student-portal-b');
        [$foreignSchool, $foreignClass, $foreignSection] = $this->schoolFixture('Foreign Student Portal School');
        $foreignStudent = $this->user('student', $foreignSchool, 'student-portal-foreign');
        $recordA = $this->studentRecord($studentA, $schoolId, $classId, $sectionId);
        $this->addResult($studentA, $schoolId, $classId, $sectionId);
        $recordB = $this->studentRecord($studentB, $schoolId, $classId, $sectionId);
        $foreignRecord = $this->studentRecord($foreignStudent, $foreignSchool, $foreignClass, $foreignSection);

        $this->actingAs($studentA)->get('/students/'.Qs::hash($recordA))->assertOk();
        $this->actingAs($studentA)->get('/dashboard')->assertOk();
        $this->actingAs($studentA)->get('/students/'.Qs::hash($recordB))->assertForbidden();
        $this->actingAs($studentA)->get('/students/'.Qs::hash($foreignRecord))->assertNotFound();
        $this->actingAs($studentA)->get('/attendance/student/'.$studentA->id)->assertOk();
        $this->actingAs($studentA)->get('/attendance/student/'.$studentB->id)->assertNotFound();
        $this->actingAs($studentA)->get('/attendance/student/'.$foreignStudent->id)->assertNotFound();
        $this->actingAs($studentA)->get('/marks/show/'.Qs::hash($studentB->id).'/2026-2027')->assertRedirect('/dashboard');
        $this->actingAs($studentA)->get('/marks/show/'.Qs::hash($foreignStudent->id).'/2026-2027')->assertRedirect('/dashboard');
        $this->actingAs($studentA)->get('/marks/select_year/'.Qs::hash($studentA->id))->assertOk();
        $ownStudentResult = $this->actingAs($studentA)->get('/marks/show/'.Qs::hash($studentA->id).'/2026-2027');
        $this->assertSame(200, $ownStudentResult->status(), $ownStudentResult->getContent());
        $this->actingAs($studentA)->put('/marks/update/1/1/1/1', [])->assertForbidden();
        $this->actingAs($studentA)->post('/attendance', [])->assertForbidden();
        $this->actingAs($studentA)->get('/payments/invoice/'.$studentA->id)->assertRedirect('/login');
    }

    private function schoolFixture($name)
    {
        $schoolId = DB::table('schools')->insertGetId([
            'name' => $name, 'code' => strtoupper(str_replace(' ', '-', $name)), 'status' => 'active',
            'timezone' => 'UTC', 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            'type' => 'current_session', 'description' => '2026-2027', 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
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
            'name' => ucfirst($role).' '.str_replace('-', ' ', $suffix),
            'email' => $suffix.'@portal.test', 'username' => $suffix.'@portal.test',
            'code' => strtoupper('PT-'.$suffix), 'user_type' => $role,
            'password' => Hash::make('PortalOwnershipTest!'), 'school_id' => $schoolId,
        ]);
    }

    private function studentRecord($student, $schoolId, $classId, $sectionId, $parentId = null)
    {
        return DB::table('student_records')->insertGetId([
            'user_id' => $student->id, 'my_parent_id' => $parentId, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'adm_no' => strtoupper('PORTAL-'.$student->id),
            'session' => '2026-2027', 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addResult($student, $schoolId, $classId, $sectionId)
    {
        $teacher = $this->user('teacher', $schoolId, 'result-teacher-'.$student->id);
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Portal Result Subject', 'slug' => 'portal-result-'.$student->id,
            'my_class_id' => $classId, 'teacher_id' => $teacher->id, 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $examId = DB::table('exams')->insertGetId([
            'name' => 'Portal Result Exam', 'term' => 1, 'year' => '2026-2027', 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('marks')->insert([
            'student_id' => $student->id, 'subject_id' => $subjectId, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'exam_id' => $examId, 'year' => '2026-2027', 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_records')->insert([
            'exam_id' => $examId, 'student_id' => $student->id, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'year' => '2026-2027', 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

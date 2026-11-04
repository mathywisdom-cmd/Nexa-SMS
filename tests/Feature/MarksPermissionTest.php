<?php

namespace Tests\Feature;

use App\Tenancy\TenantContext;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarksPermissionTest extends TestCase
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

    public function test_accountant_without_marks_permissions_cannot_view_or_manage_marks()
    {
        $schoolId = $this->school('Marks Permission Accountant School');
        $this->markFixture($schoolId, 'Accountant');
        $accountant = $this->user('accountant', $schoolId, 'accountant');

        $this->actingAs($accountant)
            ->get('/marks')
            ->assertForbidden();

        $this->actingAs($accountant)
            ->get('/marks/manage/1/1/1/1')
            ->assertForbidden();

        $this->actingAs($accountant)
            ->put('/marks/update/1/1/1/1')
            ->assertForbidden();
    }

    public function test_librarian_without_marks_permissions_cannot_view_or_manage_marks()
    {
        $schoolId = $this->school('Marks Permission Librarian School');
        $this->markFixture($schoolId, 'Librarian');
        $librarian = $this->user('librarian', $schoolId, 'librarian');

        $this->actingAs($librarian)
            ->get('/marks')
            ->assertForbidden();

        $this->actingAs($librarian)
            ->get('/marks/manage/1/1/1/1')
            ->assertForbidden();

        $this->actingAs($librarian)
            ->put('/marks/update/1/1/1/1')
            ->assertForbidden();
    }

    public function test_administrator_and_teacher_with_existing_marks_permissions_can_view_and_manage_marks()
    {
        $schoolId = $this->school('Marks Permission Authorized School');
        $route = $this->markFixture($schoolId, 'Authorized');

        foreach (['admin', 'teacher'] as $role) {
            $user = $this->user($role, $schoolId, $role);
            if ($role === 'teacher') {
                DB::table('sections')->where('id', $route['section'])->update(['teacher_id' => $user->id]);
                DB::table('subjects')->where('id', $route['subject'])->update(['teacher_id' => $user->id]);
            }

            $this->actingAs($user)
                ->get('/marks')
                ->assertOk();

            $this->actingAs($user)
                ->get('/marks/manage/'.$route['exam'].'/'.$route['class'].'/'.$route['section'].'/'.$route['subject'])
                ->assertOk();
        }
    }

    public function test_principal_and_head_teacher_pass_the_existing_marks_manage_permission_gate()
    {
        $schoolId = $this->school('Marks Permission Academic Roles School');

        foreach (['principal', 'head_teacher'] as $role) {
            $user = $this->user($role, $schoolId, $role);

            $this->actingAs($user)
                ->post('/marks/selector', [])
                ->assertSessionHasErrors(['exam_id', 'my_class_id', 'section_id', 'subject_id']);
        }
    }

    public function test_allowed_mark_management_remains_scoped_to_the_active_school()
    {
        $schoolA = $this->school('Marks Tenant School A');
        $schoolB = $this->school('Marks Tenant School B');
        $schoolAMark = $this->markFixture($schoolA, 'SchoolA');
        $schoolBMark = $this->markFixture($schoolB, 'SchoolB');
        $adminA = $this->user('admin', $schoolA, 'tenant-admin-a');

        $this->actingAs($adminA)
            ->get('/marks/manage/'.$schoolAMark['exam'].'/'.$schoolAMark['class'].'/'.$schoolAMark['section'].'/'.$schoolAMark['subject'])
            ->assertOk();

        $this->actingAs($adminA)
            ->get('/marks/manage/'.$schoolBMark['exam'].'/'.$schoolBMark['class'].'/'.$schoolBMark['section'].'/'.$schoolBMark['subject'])
            ->assertRedirect('/dashboard');
    }

    public function test_teacher_cannot_access_or_write_marks_for_unassigned_section_or_subject()
    {
        $schoolId = $this->school('Marks Assignment School');
        $route = $this->markFixture($schoolId, 'Assignment');
        $teacher = $this->user('teacher', $schoolId, 'assignment-teacher');
        DB::table('sections')->where('id', $route['section'])->update(['teacher_id' => $teacher->id]);
        DB::table('subjects')->where('id', $route['subject'])->update(['teacher_id' => $teacher->id]);

        $otherSection = DB::table('sections')->insertGetId([
            'name' => 'Unassigned Section', 'my_class_id' => $route['class'], 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherTeacher = $this->user('teacher', $schoolId, 'assignment-other-teacher');
        $otherSubject = DB::table('subjects')->insertGetId([
            'name' => 'Unassigned Subject', 'slug' => 'unassigned-subject', 'my_class_id' => $route['class'],
            'teacher_id' => $otherTeacher->id, 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($teacher)
            ->get('/marks/manage/'.$route['exam'].'/'.$route['class'].'/'.$route['section'].'/'.$route['subject'])
            ->assertOk();

        $this->actingAs($teacher)->get('/ajax/get_class_sections/'.$route['class'])
            ->assertOk()->assertJsonFragment(['id' => $route['section']])->assertJsonMissing(['id' => $otherSection]);
        $this->actingAs($teacher)->get('/ajax/get_class_subjects/'.$route['class'])
            ->assertOk()->assertJsonFragment(['id' => $route['subject']])->assertJsonMissing(['id' => $otherSubject]);
        $this->actingAs($teacher)->get('/sections')
            ->assertOk()->assertSee('Assignment Section')->assertDontSee('Unassigned Section');
        $this->actingAs($teacher)->get('/subjects')
            ->assertOk()->assertSee('Assignment Subject')->assertDontSee('Unassigned Subject');
        $this->actingAs($teacher)->get('/marks')
            ->assertOk()->assertSee('Assignment Class')->assertDontSee('Unassigned Class');

        $this->actingAs($teacher)
            ->get('/marks/manage/'.$route['exam'].'/'.$route['class'].'/'.$otherSection.'/'.$route['subject'])
            ->assertNotFound();

        $this->actingAs($teacher)
            ->get('/marks/manage/'.$route['exam'].'/'.$route['class'].'/'.$route['section'].'/'.$otherSubject)
            ->assertNotFound();

        $this->actingAs($teacher)
            ->put('/marks/update/'.$route['exam'].'/'.$route['class'].'/'.$route['section'].'/'.$otherSubject, [])
            ->assertNotFound();

        $this->actingAs($teacher)
            ->put('/marks/update/'.$route['exam'].'/'.$route['class'].'/'.$otherSection.'/'.$route['subject'], [])
            ->assertNotFound();

        $this->actingAs($teacher)
            ->post('/marks/selector', [
                'exam_id' => $route['exam'], 'my_class_id' => $route['class'],
                'section_id' => $otherSection, 'subject_id' => $route['subject'],
            ])->assertNotFound();

        $this->actingAs($teacher)
            ->post('/attendance', [
                'class_id' => $route['class'], 'section_id' => $otherSection,
                'date' => '2026-09-28', 'records' => [$route['student'] => ['status' => 'present']],
            ])->assertNotFound();
    }

    public function test_teacher_class_student_list_excludes_students_in_unassigned_sections()
    {
        $schoolId = $this->school('Student Assignment School');
        $route = $this->markFixture($schoolId, 'StudentAssignment');
        $teacher = $this->user('teacher', $schoolId, 'student-assignment-teacher');
        DB::table('users')->where('id', $route['student'])->update(['name' => 'Assigned Section Student']);
        DB::table('sections')->where('id', $route['section'])->update(['teacher_id' => $teacher->id]);

        $otherTeacher = $this->user('teacher', $schoolId, 'student-assignment-other-teacher');
        $unassignedSection = DB::table('sections')->insertGetId([
            'name' => 'Other Teacher Section', 'my_class_id' => $route['class'], 'teacher_id' => $otherTeacher->id,
            'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherStudent = $this->user('student', $schoolId, 'unassigned-section-student');
        DB::table('users')->where('id', $otherStudent->id)->update(['name' => 'Unassigned Section Student']);
        DB::table('student_records')->insert([
            'user_id' => $otherStudent->id, 'my_class_id' => $route['class'], 'section_id' => $unassignedSection,
            'adm_no' => 'OTHER-SECTION-STUDENT', 'session' => '2026-2027', 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($teacher)->get('/students/list/'.$route['class'])
            ->assertOk()->assertSee('Assigned Section Student')->assertDontSee('Unassigned Section Student');
    }

    private function school($name)
    {
        return DB::table('schools')->insertGetId([
            'name' => $name,
            'code' => strtoupper(str_replace(' ', '-', $name)),
            'status' => 'active',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user($role, $schoolId, $suffix)
    {
        app(TenantContext::class)->setSchoolId($schoolId);

        return User::create([
            'name' => ucfirst($role).' Test User',
            'email' => $suffix.'@marks-permission.test',
            'username' => $suffix.'@marks-permission.test',
            'code' => strtoupper('MP-'.$suffix),
            'user_type' => $role,
            'password' => Hash::make('MarksPermissionTest!'),
            'school_id' => $schoolId,
        ]);
    }

    private function markFixture($schoolId, $suffix)
    {
        DB::table('settings')->insert([
            'type' => 'current_session',
            'description' => '2026-2027',
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacher = $this->user('teacher', $schoolId, strtolower($suffix).'-fixture-teacher');
        $student = $this->user('student', $schoolId, strtolower($suffix).'-fixture-student');
        $classTypeId = DB::table('class_types')->insertGetId([
            'name' => $suffix.' Class Type',
            'code' => strtoupper($suffix),
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classId = DB::table('my_classes')->insertGetId([
            'name' => $suffix.' Class',
            'class_type_id' => $classTypeId,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = DB::table('sections')->insertGetId([
            'name' => $suffix.' Section',
            'my_class_id' => $classId,
            'teacher_id' => $teacher->id,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => $suffix.' Subject',
            'slug' => strtolower($suffix).'-subject',
            'my_class_id' => $classId,
            'teacher_id' => $teacher->id,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $examId = DB::table('exams')->insertGetId([
            'name' => $suffix.' Exam',
            'term' => 1,
            'year' => '2026-2027',
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('marks')->insert([
            'student_id' => $student->id,
            'subject_id' => $subjectId,
            'my_class_id' => $classId,
            'section_id' => $sectionId,
            'exam_id' => $examId,
            'year' => '2026-2027',
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('student_records')->insert([
            'user_id' => $student->id,
            'my_class_id' => $classId,
            'section_id' => $sectionId,
            'adm_no' => strtoupper($suffix).'-ADM',
            'session' => '2026-2027',
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'exam' => $examId,
            'class' => $classId,
            'section' => $sectionId,
            'subject' => $subjectId,
            'teacher' => $teacher->id,
            'student' => $student->id,
        ];
    }
}

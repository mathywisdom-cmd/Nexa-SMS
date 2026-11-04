<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToSchool;
use App\Models\AttendanceSession;
use App\Models\AttendanceRecord;
use App\Models\AcademicYear;
use App\Models\Term;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\AuditLog;
use App\Models\ClassType;
use App\Models\Dorm;
use App\Models\ExamRecord;
use App\Models\Grade;
use App\Models\Pin;
use App\Models\Promotion;
use App\Models\Receipt;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\StaffRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_school_scope_limits_queries_to_the_active_school()
    {
        app(TenantContext::class)->setSchoolId(42);

        $query = TenantTestSchoolRecord::query();

        $this->assertStringContainsString('school_id', $query->toSql());
        $this->assertContains(42, $query->getBindings());
    }

    public function test_platform_context_is_fail_closed_for_school_owned_models()
    {
        app(TenantContext::class)->allowPlatformAccess();

        $query = TenantTestSchoolRecord::query();

        $this->assertStringContainsString('school_id', $query->toSql());
        $this->assertStringContainsString('is null', strtolower($query->toSql()));
        $this->assertStringNotContainsString('school_id', TenantTestSchoolRecord::withoutGlobalScope('school')->toSql());
    }

    public function test_attendance_sessions_are_scoped_to_the_active_school()
    {
        $schoolA = DB::table('schools')->insertGetId([
            'name' => 'Attendance Scope School A',
            'code' => 'ATTENDANCE-SCOPE-A',
            'status' => 'active',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $schoolB = DB::table('schools')->insertGetId([
            'name' => 'Attendance Scope School B',
            'code' => 'ATTENDANCE-SCOPE-B',
            'status' => 'active',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classA = DB::table('my_classes')->insertGetId([
            'name' => 'Attendance Class A',
            'school_id' => $schoolA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classB = DB::table('my_classes')->insertGetId([
            'name' => 'Attendance Class B',
            'school_id' => $schoolB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionA = DB::table('sections')->insertGetId([
            'name' => 'Attendance Section A',
            'my_class_id' => $classA,
            'school_id' => $schoolA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionB = DB::table('sections')->insertGetId([
            'name' => 'Attendance Section B',
            'my_class_id' => $classB,
            'school_id' => $schoolB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionA = DB::table('attendance_sessions')->insertGetId([
            'school_id' => $schoolA,
            'my_class_id' => $classA,
            'section_id' => $sectionA,
            'attendance_date' => '2026-09-28',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sessionB = DB::table('attendance_sessions')->insertGetId([
            'school_id' => $schoolB,
            'my_class_id' => $classB,
            'section_id' => $sectionB,
            'attendance_date' => '2026-09-28',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TenantContext::class)->setSchoolId($schoolA);
        $schoolAIds = AttendanceSession::query()->pluck('id')->all();
        $this->assertSame([$sessionA], $schoolAIds);
        $this->assertNotContains($sessionB, $schoolAIds);

        app(TenantContext::class)->setSchoolId($schoolB);
        $schoolBIds = AttendanceSession::query()->pluck('id')->all();
        $this->assertSame([$sessionB], $schoolBIds);
        $this->assertNotContains($sessionA, $schoolBIds);
    }

    public function test_academic_periods_and_legacy_school_records_are_scoped()
    {
        app(TenantContext::class)->setSchoolId(91);
        foreach ([AcademicYear::class, Term::class, Exam::class, Mark::class, Payment::class, PaymentRecord::class] as $model) {
            $query = $model::query();
            $this->assertContains(91, $query->getBindings(), $model.' must be tenant scoped');
            $this->assertStringContainsString('school_id', $query->toSql(), $model.' must filter by school');
        }
    }

    public function test_major_school_owned_models_apply_the_active_school_scope()
    {
        app(TenantContext::class)->setSchoolId(137);
        $models = [
            User::class, ClassType::class, MyClass::class, Section::class, Subject::class,
            \App\Models\StudentRecord::class, Exam::class, Mark::class, ExamRecord::class,
            Grade::class, Dorm::class, Pin::class,
            StaffRecord::class, Payment::class, PaymentRecord::class, Receipt::class,
            TimeTable::class, TimeTableRecord::class, TimeSlot::class, Promotion::class,
            Setting::class, Skill::class, AcademicYear::class, Term::class,
            AttendanceSession::class, AttendanceRecord::class, AuditLog::class,
        ];

        foreach ($models as $model) {
            $query = $model::query();
            $this->assertContains(137, $query->getBindings(), $model.' must bind the active school ID');
            $this->assertStringContainsString('school_id', $query->toSql(), $model.' must filter by school');
        }
    }
}

class TenantTestSchoolRecord extends Model
{
    use BelongsToSchool;

    protected $table = 'student_records';
}

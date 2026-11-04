<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Term;
use App\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function index()
    {
        $this->requireSchoolContext();
        $sections = $this->availableSections()->with('my_class')->orderBy('my_class_id')->orderBy('name')->get();
        $classId = request('class_id');
        $sectionId = request('section_id');
        $date = request('date', date('Y-m-d'));
        $selectedSection = $sectionId ? $sections->firstWhere('id', (int) $sectionId) : null;
        $students = collect();
        $session = null;

        if ($selectedSection && (!$classId || (int) $selectedSection->my_class_id === (int) $classId)) {
            $students = StudentRecord::with('user')
                ->where('my_class_id', $selectedSection->my_class_id)
                ->where('section_id', $selectedSection->id)
                ->orderBy('user_id')->get();
            $session = AttendanceSession::with('records')->where([
                'my_class_id' => $selectedSection->my_class_id,
                'section_id' => $selectedSection->id,
                'attendance_date' => $date,
            ])->first();
        }

        $saved = $session ? $session->records->keyBy('student_id') : collect();
        $years = AcademicYear::with('terms')->orderByDesc('starts_on')->orderBy('name')->get();

        return view('pages.support_team.attendance.index', compact('sections', 'classId', 'sectionId', 'date', 'students', 'saved', 'years', 'session'));
    }

    public function store()
    {
        $this->requireSchoolContext();
        $data = request()->validate([
            'class_id' => 'required|integer',
            'section_id' => 'required|integer',
            'date' => 'required|date',
            'academic_year_id' => 'nullable|integer',
            'term_id' => 'nullable|integer',
            'records' => 'required|array|min:1',
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'records.*.remarks' => 'nullable|string|max:255',
        ]);

        $section = $this->availableSections()->where('my_class_id', $data['class_id'])->findOrFail($data['section_id']);
        $ids = array_map('intval', array_keys($data['records']));
        $roster = StudentRecord::where('my_class_id', $data['class_id'])
            ->where('section_id', $section->id)->pluck('user_id')->map(function ($id) { return (int) $id; })->all();
        sort($roster);
        $submittedIds = array_values(array_unique($ids));
        sort($submittedIds);
        abort_unless($roster === $submittedIds, 422, 'Attendance must include every student in this section and no others.');

        $year = !empty($data['academic_year_id']) ? AcademicYear::findOrFail($data['academic_year_id']) : null;
        $term = !empty($data['term_id']) ? Term::findOrFail($data['term_id']) : null;
        if ($term && (!$year || (int) $term->academic_year_id !== (int) $year->id)) {
            abort(422, 'The selected term does not belong to the selected academic year.');
        }

        DB::transaction(function () use ($data, $section, $year, $term) {
            $session = AttendanceSession::firstOrNew([
                'my_class_id' => $section->my_class_id,
                'section_id' => $section->id,
                'attendance_date' => $data['date'],
            ]);
            $session->academic_year_id = $year ? $year->id : null;
            $session->term_id = $term ? $term->id : null;
            $session->marked_by = Auth::id();
            $session->save();

            foreach ($data['records'] as $studentId => $record) {
                AttendanceRecord::updateOrCreate([
                    'attendance_session_id' => $session->id,
                    'student_id' => (int) $studentId,
                ], [
                    'status' => $record['status'],
                    'remarks' => $record['remarks'] ?? null,
                ]);
            }

            app(AuditLogger::class)->record('attendance.marked', $session, 'Class attendance saved.', [
                'attendance_date' => $data['date'], 'class_id' => $section->my_class_id,
                'section_id' => $section->id, 'record_count' => count($data['records']),
            ]);
        });

        return redirect()->route('attendance.index', [
            'class_id' => $data['class_id'], 'section_id' => $section->id, 'date' => $data['date'],
        ])->with('flash_success', 'Attendance saved.');
    }

    public function history()
    {
        $this->requireSchoolContext();
        $query = AttendanceSession::with(['myClass', 'section', 'records', 'academicYear', 'term'])
            ->whereIn('section_id', $this->availableSections()->select('id'));
        if (request('class_id')) {
            $query->where('my_class_id', request('class_id'));
        }
        if (request('section_id')) {
            $query->where('section_id', request('section_id'));
        }
        if (request('from')) {
            $query->whereDate('attendance_date', '>=', request('from'));
        }
        if (request('to')) {
            $query->whereDate('attendance_date', '<=', request('to'));
        }
        if (request('academic_year_id')) {
            $query->where('academic_year_id', request('academic_year_id'));
        }
        if (request('term_id')) {
            $query->where('term_id', request('term_id'));
        }

        $sessions = $query->orderByDesc('attendance_date')->paginate(25)->appends(request()->query());
        $sections = $this->availableSections()->with('my_class')->get();
        $years = AcademicYear::with('terms')->orderByDesc('starts_on')->get();
        return view('pages.support_team.attendance.history', compact('sessions', 'sections', 'years'));
    }

    public function studentHistory(User $student)
    {
        $this->requireSchoolContext();
        $actor = Auth::user();
        if ($actor->user_type === 'student') {
            abort_unless((int) $actor->id === (int) $student->id, 404);
        } elseif ($actor->user_type === 'parent') {
            abort_unless(Qs::userIsMyChild($student->id, $actor->id), 404);
        } else {
            abort_unless(app(\App\Services\PermissionService::class)->userCan('attendance.view'), 403);
        }

        $studentRecord = StudentRecord::where('user_id', $student->id)->firstOrFail();
        if (Qs::userIsTeacher()) {
            abort_unless($this->availableSections()->where('id', $studentRecord->section_id)->exists(), 404);
        }

        $recordsQuery = AttendanceRecord::with(['session.myClass', 'session.section', 'session.academicYear', 'session.term'])
            ->where('student_id', $student->id)
            ->whereHas('session', function ($query) use ($studentRecord) {
                $query->where('section_id', $studentRecord->section_id);
            });
        if (request('academic_year_id')) $recordsQuery->whereHas('session', function ($q) { $q->where('academic_year_id', request('academic_year_id')); });
        if (request('term_id')) $recordsQuery->whereHas('session', function ($q) { $q->where('term_id', request('term_id')); });

        $records = $recordsQuery->get()->sortByDesc(function ($record) { return $record->session->attendance_date; })->values();
        $total = $records->count();
        $attended = $records->whereIn('status', ['present', 'late'])->count();
        $percentage = $total ? round(($attended / $total) * 100, 1) : null;
        $monthly = $records->groupBy(function ($record) { return $record->session->attendance_date->format('Y-m'); })
            ->map(function ($monthRecords) {
                $count = $monthRecords->count();
                $present = $monthRecords->whereIn('status', ['present', 'late'])->count();
                return ['total' => $count, 'present' => $present, 'absent' => $monthRecords->where('status', 'absent')->count(),
                    'late' => $monthRecords->where('status', 'late')->count(), 'excused' => $monthRecords->where('status', 'excused')->count(),
                    'percentage' => $count ? round(($present / $count) * 100, 1) : 0];
            });
        $years = AcademicYear::with('terms')->orderByDesc('starts_on')->get();

        return view('pages.support_team.attendance.student', compact('student', 'studentRecord', 'records', 'total', 'attended', 'percentage', 'monthly', 'years'));
    }

    public function statusReport($status)
    {
        abort_unless(in_array($status, ['absent', 'late', 'excused'], true), 404);
        $this->requireSchoolContext();
        $records = AttendanceRecord::with(['student', 'session.myClass', 'session.section', 'session.academicYear', 'session.term'])
            ->where('status', $status)
            ->whereHas('session', function ($query) {
                $query->whereIn('section_id', $this->availableSections()->select('id'));
                if (request('class_id')) $query->where('my_class_id', request('class_id'));
                if (request('section_id')) $query->where('section_id', request('section_id'));
                if (request('from')) $query->whereDate('attendance_date', '>=', request('from'));
                if (request('to')) $query->whereDate('attendance_date', '<=', request('to'));
                if (request('academic_year_id')) $query->where('academic_year_id', request('academic_year_id'));
                if (request('term_id')) $query->where('term_id', request('term_id'));
            })->latest('created_at')->paginate(50)->appends(request()->query());

        return view('pages.support_team.attendance.report', compact('records', 'status'));
    }

    public function show(AttendanceSession $attendanceSession)
    {
        $this->requireSchoolContext();
        $this->authorizeSession($attendanceSession);
        $attendanceSession->load(['myClass', 'section', 'records.student']);
        return view('pages.support_team.attendance.show', compact('attendanceSession'));
    }

    public function printView(AttendanceSession $attendanceSession)
    {
        $this->requireSchoolContext();
        $this->authorizeSession($attendanceSession);
        $attendanceSession->load(['myClass', 'section', 'records.student']);
        return view('pages.support_team.attendance.print', compact('attendanceSession'));
    }

    protected function availableSections()
    {
        $query = Section::query();
        if (Qs::userIsTeacher()) {
            $query->where('teacher_id', Auth::id());
        }
        return $query;
    }

    protected function requireSchoolContext()
    {
        abort_unless(app(TenantContext::class)->schoolId(), 403, 'Attendance requires an active school account.');
    }

    protected function authorizeSession(AttendanceSession $session)
    {
        abort_unless($this->availableSections()->where('id', $session->section_id)->exists(), 404);
    }
}

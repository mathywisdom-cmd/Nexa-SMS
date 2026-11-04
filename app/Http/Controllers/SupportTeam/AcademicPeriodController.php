<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Term;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcademicPeriodController extends Controller
{
    public function index()
    {
        $this->requireSchoolContext();
        $years = AcademicYear::with('terms')->orderByDesc('starts_on')->orderBy('name')->get();
        return view('pages.support_team.academic_periods.index', compact('years'));
    }

    public function storeYear()
    {
        $this->requireSchoolContext();
        $data = request()->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('academic_years', 'name')->where('school_id', app(TenantContext::class)->schoolId())],
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'is_current' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($data) {
            if (!empty($data['is_current'])) {
                AcademicYear::where('is_current', true)->update(['is_current' => false]);
            }
            $year = AcademicYear::create($data);
            app(AuditLogger::class)->record('academic_year.created', $year, 'Academic year created.', ['name' => $year->name]);
        });

        return back()->with('flash_success', 'Academic year created.');
    }

    public function updateYear(AcademicYear $academicYear)
    {
        $this->requireSchoolContext();
        $data = request()->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('academic_years', 'name')->where('school_id', app(TenantContext::class)->schoolId())->ignore($academicYear->id)],
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'is_current' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($data, $academicYear) {
            if (!empty($data['is_current'])) {
                AcademicYear::where('is_current', true)->where('id', '<>', $academicYear->id)->update(['is_current' => false]);
            }
            $academicYear->update($data);
            app(AuditLogger::class)->record('academic_year.updated', $academicYear, 'Academic year updated.', ['name' => $academicYear->name]);
        });

        return back()->with('flash_success', 'Academic year updated.');
    }

    public function destroyYear(AcademicYear $academicYear)
    {
        $this->requireSchoolContext();
        $references = ['student_records' => ['academic_year_id'], 'exams' => ['academic_year_id'], 'marks' => ['academic_year_id'],
            'exam_records' => ['academic_year_id'], 'payments' => ['academic_year_id'], 'payment_records' => ['academic_year_id'],
            'receipts' => ['academic_year_id'], 'time_table_records' => ['academic_year_id'], 'attendance_sessions' => ['academic_year_id'],
            'promotions' => ['from_academic_year_id', 'to_academic_year_id']];
        foreach ($references as $table => $columns) {
            foreach ($columns as $column) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, $column)
                    && DB::table($table)->where('school_id', $academicYear->school_id)->where($column, $academicYear->id)->exists()) {
                    return back()->with('pop_error', 'This academic year is referenced by existing records and cannot be deleted.');
                }
            }
        }
        $academicYear->delete();
        app(AuditLogger::class)->record('academic_year.deleted', $academicYear, 'Academic year deleted.', ['name' => $academicYear->name]);
        return back()->with('flash_success', 'Academic year and its terms deleted.');
    }

    public function storeTerm(AcademicYear $academicYear)
    {
        $this->requireSchoolContext();
        $data = request()->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('terms', 'name')->where('school_id', app(TenantContext::class)->schoolId())->where('academic_year_id', $academicYear->id)],
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
        ]);
        $term = $academicYear->terms()->create($data);
        app(AuditLogger::class)->record('term.created', $term, 'Academic term created.', ['name' => $term->name]);
        return back()->with('flash_success', 'Term created.');
    }

    public function updateTerm(AcademicYear $academicYear, Term $term)
    {
        $this->requireSchoolContext();
        abort_unless($term->academic_year_id === $academicYear->id, 404);
        $data = request()->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('terms', 'name')->where('school_id', app(TenantContext::class)->schoolId())->where('academic_year_id', $academicYear->id)->ignore($term->id)],
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
        ]);
        $term->update($data);
        app(AuditLogger::class)->record('term.updated', $term, 'Academic term updated.', ['name' => $term->name]);
        return back()->with('flash_success', 'Term updated.');
    }

    public function destroyTerm(AcademicYear $academicYear, Term $term)
    {
        $this->requireSchoolContext();
        abort_unless($term->academic_year_id === $academicYear->id, 404);
        foreach (['exams', 'marks', 'exam_records', 'time_table_records', 'attendance_sessions'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, 'term_id')
                && DB::table($table)->where('school_id', $term->school_id)->where('term_id', $term->id)->exists()) {
                return back()->with('pop_error', 'This term is referenced by existing records and cannot be deleted.');
            }
        }
        $term->delete();
        app(AuditLogger::class)->record('term.deleted', $term, 'Academic term deleted.', ['name' => $term->name]);
        return back()->with('flash_success', 'Term deleted.');
    }

    protected function requireSchoolContext()
    {
        abort_unless(app(TenantContext::class)->schoolId(), 403, 'Academic periods require an active school account.');
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\Term;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Schema;

trait SynchronizesAcademicPeriod
{
    protected static function bootSynchronizesAcademicPeriod()
    {
        static::saving(function ($model) {
            $schoolId = $model->getAttribute('school_id') ?: app(TenantContext::class)->schoolId();
            if (!$schoolId) return;

            $legacyYear = $model->getAttribute('year') ?: $model->getAttribute('session');
            $yearChanged = $model->isDirty('year') || $model->isDirty('session');
            if ((!$model->getAttribute('academic_year_id') || $yearChanged) && $legacyYear && strlen((string) $legacyYear) <= 40) {
                $model->setAttribute('academic_year_id', AcademicYear::where('school_id', $schoolId)->where('name', trim((string) $legacyYear))->value('id'));
            }

            if (!Schema::hasColumn($model->getTable(), 'term_id')) return;

            if (!$model->getAttribute('term_id') || $model->isDirty('term') || $model->isDirty('exam_id') || $yearChanged) {
                $model->setAttribute('term_id', null);
                $termNumber = $model->getAttribute('term');
                if (!$termNumber && $model->getAttribute('exam_id')) {
                    $exam = Exam::where('school_id', $schoolId)->find($model->getAttribute('exam_id'));
                    if ($exam) {
                        $termNumber = $exam->term;
                        if (!$model->getAttribute('academic_year_id')) $model->setAttribute('academic_year_id', $exam->academic_year_id);
                    }
                }
                if ($termNumber && $model->getAttribute('academic_year_id')) {
                    $termId = Term::where('school_id', $schoolId)
                        ->where('academic_year_id', $model->getAttribute('academic_year_id'))
                        ->where('name', 'Term '.(int) $termNumber)->value('id');
                    $model->setAttribute('term_id', $termId);
                }
            }
        });
    }
}

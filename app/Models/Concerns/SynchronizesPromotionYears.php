<?php

namespace App\Models\Concerns;

use App\Models\AcademicYear;
use App\Tenancy\TenantContext;

trait SynchronizesPromotionYears
{
    protected static function bootSynchronizesPromotionYears()
    {
        static::saving(function ($model) {
            $schoolId = $model->getAttribute('school_id') ?: app(TenantContext::class)->schoolId();
            if (!$schoolId) return;
            foreach (['from_session' => 'from_academic_year_id', 'to_session' => 'to_academic_year_id'] as $legacy => $period) {
                if (!$model->getAttribute($period) || $model->isDirty($legacy)) {
                    $name = trim((string) $model->getAttribute($legacy));
                    $yearId = $name !== '' && strlen($name) <= 40
                        ? AcademicYear::where('school_id', $schoolId)->where('name', $name)->value('id') : null;
                    $model->setAttribute($period, $yearId);
                }
            }
        });
    }
}

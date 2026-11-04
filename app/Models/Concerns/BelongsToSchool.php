<?php

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToSchool
{
    protected static function bootBelongsToSchool()
    {
        static::addGlobalScope('school', function (Builder $builder) {
            $tenant = app(TenantContext::class);

            if (!$tenant->schoolId() && !$tenant->hasPlatformAccess()) {
                return;
            }

            if ($tenant->hasPlatformAccess()) {
                $builder->whereNull($builder->getModel()->getTable().'.school_id');
                return;
            }

            $builder->where($builder->getModel()->getTable().'.school_id', $tenant->schoolId());
        });

        static::creating(function ($model) {
            $tenant = app(TenantContext::class);

            if ($tenant->schoolId()) {
                $model->setAttribute('school_id', $tenant->schoolId());
            }
        });
    }

    public function school()
    {
        return $this->belongsTo(\App\Models\School::class);
    }
}

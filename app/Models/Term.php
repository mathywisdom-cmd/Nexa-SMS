<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Eloquent;

class Term extends Eloquent
{
    use BelongsToSchool;

    protected $fillable = ['academic_year_id', 'name', 'starts_on', 'ends_on'];

    protected $dates = ['starts_on', 'ends_on'];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }
}

<?php

namespace App\Models;

use Eloquent;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\SynchronizesAcademicPeriod;

class TimeTableRecord extends Eloquent
{
    use BelongsToSchool, SynchronizesAcademicPeriod;
    protected $fillable = ['name', 'my_class_id', 'exam_id', 'year', 'academic_year_id', 'term_id'];

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}

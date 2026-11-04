<?php

namespace App\Models;

use Eloquent;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\SynchronizesAcademicPeriod;

class Exam extends Eloquent
{
    use BelongsToSchool, SynchronizesAcademicPeriod;
    protected $fillable = ['name', 'term', 'year', 'academic_year_id', 'term_id'];
}

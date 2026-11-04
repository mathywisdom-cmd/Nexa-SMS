<?php

namespace App\Models;

use Eloquent;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\SynchronizesAcademicPeriod;

class ExamRecord extends Eloquent
{
    use BelongsToSchool, SynchronizesAcademicPeriod;
    protected $fillable = ['exam_id', 'my_class_id', 'student_id', 'section_id', 'af', 'af_id', 'ps', 'ps_id','t_comment', 'p_comment', 'year', 'total', 'ave', 'class_ave', 'pos', 'academic_year_id', 'term_id'];
}

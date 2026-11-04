<?php

namespace App\Models;

use Eloquent;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\SynchronizesAcademicPeriod;

class Payment extends Eloquent
{
    use BelongsToSchool, SynchronizesAcademicPeriod;
    protected $fillable = ['title', 'amount', 'my_class_id', 'description', 'year', 'ref_no', 'academic_year_id'];

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }
}

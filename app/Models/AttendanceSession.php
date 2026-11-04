<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Eloquent;

class AttendanceSession extends Eloquent
{
    use BelongsToSchool;

    protected $fillable = ['my_class_id', 'section_id', 'attendance_date', 'academic_year_id', 'term_id', 'marked_by'];

    protected $dates = ['attendance_date'];

    public function records()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function myClass()
    {
        return $this->belongsTo(MyClass::class, 'my_class_id');
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}

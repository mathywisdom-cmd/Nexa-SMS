<?php

namespace App\Models;

use App\User;
use Eloquent;
use App\Models\Concerns\BelongsToSchool;

class Section extends Eloquent
{
    use BelongsToSchool;
    protected $fillable = ['name', 'my_class_id', 'active', 'teacher_id'];

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function student_record()
    {
        return $this->hasMany(StudentRecord::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use App\User;
use Eloquent;

class AttendanceRecord extends Eloquent
{
    use BelongsToSchool;

    protected $fillable = ['attendance_session_id', 'student_id', 'status', 'remarks'];

    public function session()
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}

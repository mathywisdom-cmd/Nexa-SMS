<?php

namespace App\Models;

use App\User;
use Eloquent;
use App\Models\Concerns\BelongsToSchool;

class Pin extends Eloquent
{
    use BelongsToSchool;
    protected $fillable = ['code', 'user_id', 'student_id', 'times_used', 'used'];

    public function user($foreign = NULL)
    {
        return $this->belongsTo(User::class, $foreign);
    }

    public function student()
    {
        return $this->user('student_id');
    }

}

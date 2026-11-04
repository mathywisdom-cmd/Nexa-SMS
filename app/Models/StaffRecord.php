<?php

namespace App\Models;

use App\User;
use Eloquent;
use App\Models\Concerns\BelongsToSchool;

class StaffRecord extends Eloquent
{
    use BelongsToSchool;
    protected $fillable = ['code', 'emp_date', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}

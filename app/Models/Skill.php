<?php

namespace App\Models;

use Eloquent;
use App\Models\Concerns\BelongsToSchool;

class Skill extends Eloquent
{
    use BelongsToSchool;
    //protected  $fillable = ['name', 'skill_type', 'class_type'];
}

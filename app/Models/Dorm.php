<?php

namespace App\Models;

use Eloquent;
use App\Models\Concerns\BelongsToSchool;

class Dorm extends Eloquent
{
    use BelongsToSchool;
    protected $fillable = ['name', 'description'];
}

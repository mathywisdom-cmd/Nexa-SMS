<?php

namespace App\Models;

use Eloquent;
use App\Models\Concerns\BelongsToSchool;

class Grade extends Eloquent
{
    use BelongsToSchool;
    protected $fillable = ['name', 'class_type_id', 'mark_from', 'mark_to', 'remark'];
}

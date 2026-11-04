<?php

namespace App\Models;

use Eloquent;
use App\Models\Concerns\BelongsToSchool;

class Setting extends Eloquent
{
    use BelongsToSchool;
    protected $fillable = ['type', 'description'];
}

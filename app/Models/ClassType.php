<?php

namespace App\Models;

use Eloquent;
use App\Models\Concerns\BelongsToSchool;

class ClassType extends Eloquent
{
    use BelongsToSchool;
    //
}

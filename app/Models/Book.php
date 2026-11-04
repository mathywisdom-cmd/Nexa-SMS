<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToSchool;

class Book extends Model
{
    use BelongsToSchool;
    //
}

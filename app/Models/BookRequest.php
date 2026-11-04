<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToSchool;

class BookRequest extends Model
{
    use BelongsToSchool;
    //
}

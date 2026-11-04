<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Eloquent;

class AcademicYear extends Eloquent
{
    use BelongsToSchool;

    protected $fillable = ['name', 'starts_on', 'ends_on', 'is_current'];

    protected $dates = ['starts_on', 'ends_on'];

    public function terms()
    {
        return $this->hasMany(Term::class);
    }
}

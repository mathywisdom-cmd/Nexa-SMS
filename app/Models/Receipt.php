<?php

namespace App\Models;

use App\User;
use Eloquent;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\SynchronizesAcademicPeriod;

class Receipt extends Eloquent
{
    use BelongsToSchool, SynchronizesAcademicPeriod;
    protected $fillable = ['pr_id', 'year', 'balance', 'amt_paid', 'academic_year_id'];

    public function pr()
    {
        return $this->belongsTo(PaymentRecord::class, 'pr_id');
    }

}

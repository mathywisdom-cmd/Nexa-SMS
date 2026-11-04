<?php

namespace App\Models;

use App\User;
use Eloquent;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\SynchronizesAcademicPeriod;

class PaymentRecord extends Eloquent
{
    use BelongsToSchool, SynchronizesAcademicPeriod;
    protected $fillable =['student_id', 'payment_id', 'amt_paid', 'year', 'paid', 'balance', 'ref_no', 'academic_year_id'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function receipt()
    {
        return $this->hasMany(Receipt::class, 'pr_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use App\User;
use Eloquent;

class AuditLog extends Eloquent
{
    use BelongsToSchool;

    protected $fillable = [
        'school_id', 'actor_id', 'action', 'auditable_type', 'auditable_id',
        'description', 'properties', 'ip_address', 'user_agent',
    ];

    protected $casts = ['properties' => 'array'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

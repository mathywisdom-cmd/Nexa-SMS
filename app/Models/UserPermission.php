<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermission extends Model
{
    protected $fillable = ['user_id', 'permission_id', 'granted_by', 'allowed'];

    protected $casts = ['allowed' => 'boolean'];

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}

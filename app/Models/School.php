<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class School extends Model
{
    protected $fillable = [
        'name', 'code', 'registration_number', 'logo', 'email', 'phone', 'address',
        'status', 'timezone', 'currency',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($school) {
            if (empty($school->slug)) {
                $school->slug = static::uniqueSlug($school->name);
            }
        });
    }

    public static function uniqueSlug($name, $ignoreId = null)
    {
        $base = Str::slug($name) ?: 'school';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)
            ->when($ignoreId, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function users()
    {
        return $this->hasMany(\App\User::class);
    }
}

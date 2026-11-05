<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

/**
 * Keeps Laravel's existing password-based accounts working while excluding
 * Supabase-managed profiles, whose legacy password hash is intentionally NULL.
 */
class LegacyPasswordUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        $model = $this->createModel();

        return $this->newModelQuery($model)
            ->where($model->getAuthIdentifierName(), $identifier)
            ->whereNotNull('password')
            ->first();
    }

    public function retrieveByToken($identifier, $token)
    {
        $model = $this->createModel();
        $user = $this->newModelQuery($model)
            ->where($model->getAuthIdentifierName(), $identifier)
            ->whereNotNull('password')
            ->first();

        if (!$user) {
            return null;
        }

        $rememberToken = $user->getRememberToken();

        return $rememberToken && hash_equals($rememberToken, $token) ? $user : null;
    }

    public function retrieveByCredentials(array $credentials)
    {
        if (empty($credentials) || (count($credentials) === 1 && Str::contains($this->firstCredentialKey($credentials), 'password'))) {
            return null;
        }

        $query = $this->newModelQuery()->whereNotNull('password');

        foreach ($credentials as $key => $value) {
            if (Str::contains($key, 'password')) {
                continue;
            }

            if (is_array($value) || $value instanceof Arrayable) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        return $query->first();
    }
}

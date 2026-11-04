<?php

namespace App\Services;

use App\User;

class PermissionService
{
    public function allows($user, $permission)
    {
        if (!$user) {
            return false;
        }

        $isPlatformRole = $user->user_type === 'super_admin';
        if ($isPlatformRole !== (strpos($permission, 'platform.') === 0)) {
            return false;
        }

        $user->loadMissing(['role.permissions', 'userPermissions.permission']);
        $override = $user->userPermissions->first(function ($grant) use ($permission) {
            return $grant->permission && $grant->permission->name === $permission;
        });
        if ($override) {
            return $override->allowed;
        }

        $role = $user->role;
        return $role && $role->permissions->contains('name', $permission);
    }

    public function userCan($permission)
    {
        return $this->allows(auth()->user(), $permission);
    }
}

<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class RolePermissionController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->orderBy('scope')->orderBy('label')->get();
        $permissions = Permission::orderBy('name')->get()->groupBy(function ($permission) {
            return strpos($permission->name, 'platform.') === 0 ? 'Platform' : 'School';
        });

        return view('pages.super_admin.permissions.index', compact('roles', 'permissions'));
    }

    public function update(Role $role)
    {
        $data = request()->validate(['permissions' => 'sometimes|array', 'permissions.*' => 'required|string|exists:permissions,name']);
        $names = $data['permissions'] ?? [];
        $platform = $role->scope === 'platform';
        foreach ($names as $name) {
            abort_if((strpos($name, 'platform.') === 0) !== $platform, 422, 'Permission scope does not match this role.');
        }

        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        DB::transaction(function () use ($role, $names) {
            $ids = Permission::whereIn('name', $names)->pluck('id')->all();
            $role->permissions()->sync($ids);
        });
        app(AuditLogger::class)->record('role.permissions_updated', null, 'Role permissions updated.', [
            'role' => $role->name, 'before' => $before, 'after' => $names,
        ]);

        return redirect()->route('owner.permissions.index')->with('flash_success', 'Role permissions updated.');
    }
}

@extends('layouts.master')
@section('page_title', 'Role permissions')
@section('content')
<h4 class="mb-3">Role permissions</h4>
<p class="text-muted">Platform permissions are restricted to platform roles. School permissions are restricted to school roles.</p>
@foreach($roles as $role)
    <div class="card mb-3"><div class="card-header"><strong>{{ $role->label }}</strong> <span class="text-muted">({{ $role->scope }})</span></div>
        <form method="POST" action="{{ route('owner.permissions.update', $role) }}">@csrf @method('PUT')
            <div class="card-body">
                @foreach(($role->scope === 'platform' ? ['Platform' => ($permissions['Platform'] ?? collect())] : ['School' => ($permissions['School'] ?? collect())]) as $group => $items)
                    <div class="row">
                        @foreach($items as $permission)
                            <label class="col-md-4 mb-2"><input type="checkbox" name="permissions[]" value="{{ $permission->name }}" {{ $role->permissions->contains('name', $permission->name) ? 'checked' : '' }}> {{ $permission->description }}</label>
                        @endforeach
                    </div>
                @endforeach
            </div><div class="card-footer text-right"><button class="btn btn-primary">Save permissions</button></div>
        </form>
    </div>
@endforeach
@endsection

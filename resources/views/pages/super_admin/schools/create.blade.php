@extends('layouts.master')
@section('page_title', 'Create School')
@section('content')
<div class="card"><div class="card-header"><h5>Create school and first administrator</h5></div><div class="card-body">
<form method="post" action="{{ route('owner.schools.store') }}">@csrf
<div class="row">
@foreach(['name' => 'School name', 'code' => 'School code', 'registration_number' => 'Registration number', 'email' => 'School email', 'phone' => 'Phone', 'address' => 'Address', 'timezone' => 'Timezone', 'currency' => 'Currency'] as $field => $label)
<div class="col-md-6 form-group"><label>{{ $label }}</label><input name="{{ $field }}" class="form-control" value="{{ old($field, $field === 'timezone' ? 'Africa/Nairobi' : ($field === 'currency' ? 'UGX' : '')) }}" {{ in_array($field, ['name','code','timezone','currency']) ? 'required' : '' }}></div>
@endforeach
<div class="col-md-6 form-group"><label>Status</label><select class="form-control" name="status"><option value="active">Active</option><option value="trial">Trial</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select></div>
<div class="col-12"><hr><h6>First school administrator</h6></div>
<div class="col-md-6 form-group"><label>Name</label><input class="form-control" name="admin_name" value="{{ old('admin_name') }}" required></div>
<div class="col-md-6 form-group"><label>Email / username</label><input class="form-control" type="email" name="admin_email" value="{{ old('admin_email') }}" required></div>
<div class="col-md-6 form-group"><label>Password</label><input class="form-control" type="password" name="admin_password" required></div>
<div class="col-md-6 form-group"><label>Confirm password</label><input class="form-control" type="password" name="admin_password_confirmation" required></div>
</div><button class="btn btn-primary">Create school</button> <a class="btn btn-light" href="{{ route('owner.schools.index') }}">Cancel</a>
</form></div></div>
@endsection

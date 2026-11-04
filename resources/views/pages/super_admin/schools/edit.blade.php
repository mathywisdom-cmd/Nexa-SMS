@extends('layouts.master')
@section('page_title', 'Edit School')
@section('content')
<div class="card"><div class="card-header"><h5>Edit {{ $school->name }}</h5><small>{{ number_format($school->users_count) }} user accounts</small></div><div class="card-body">
<form method="post" action="{{ route('owner.schools.update', $school) }}">@csrf @method('PUT')
<div class="row">
@foreach(['name' => 'School name', 'code' => 'School code', 'registration_number' => 'Registration number', 'email' => 'School email', 'phone' => 'Phone', 'address' => 'Address', 'timezone' => 'Timezone', 'currency' => 'Currency'] as $field => $label)
<div class="col-md-6 form-group"><label>{{ $label }}</label><input name="{{ $field }}" class="form-control" value="{{ old($field, $school->$field) }}" {{ in_array($field, ['name','code','timezone','currency']) ? 'required' : '' }}></div>
@endforeach
<div class="col-md-6 form-group"><label>Status</label><select class="form-control" name="status">@foreach(['active','trial','inactive','suspended'] as $status)<option value="{{ $status }}" {{ old('status', $school->status) === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>@endforeach</select></div>
</div><button class="btn btn-primary">Save changes</button> <a class="btn btn-light" href="{{ route('owner.schools.index') }}">Back</a>
</form></div></div>
@endsection

@extends('layouts.master')
@section('page_title', 'Schools')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3"><h4>Schools</h4><a class="btn btn-primary" href="{{ route('owner.schools.create') }}">Create school</a></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Code</th><th>Status</th><th>Students</th><th>Teachers</th><th></th></tr></thead><tbody>
@forelse($schools as $school)<tr><td>{{ $school->name }}</td><td>{{ $school->code }}</td><td>{{ ucfirst($school->status) }}</td><td>{{ $school->students_count }}</td><td>{{ $school->teachers_count }}</td><td><a href="{{ route('owner.schools.edit', $school) }}">Edit</a></td></tr>@empty<tr><td colspan="6" class="text-center text-muted">No schools found.</td></tr>@endforelse
</tbody></table></div><div class="card-footer">{{ $schools->links() }}</div></div>
@endsection

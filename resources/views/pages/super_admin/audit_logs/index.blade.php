@extends('layouts.master')
@section('page_title', 'Audit Logs')
@section('content')
<div class="card"><div class="card-body"><form method="get" action="{{ route('owner.audit_logs.index') }}"><div class="form-row"><div class="form-group col-md-4"><label>School</label><select class="form-control" name="school_id"><option value="">All schools and platform</option>@foreach($schools as $school)<option value="{{ $school->id }}" {{ (string)request('school_id') === (string)$school->id ? 'selected' : '' }}>{{ $school->name }}</option>@endforeach</select></div><div class="form-group col-md-4"><label>Action contains</label><input class="form-control" name="action" value="{{ request('action') }}"></div><div class="form-group col-md-2 align-self-end"><button class="btn btn-primary">Filter</button></div></div></form></div></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>When</th><th>School</th><th>Actor</th><th>Action</th><th>Description</th><th>Record</th></tr></thead><tbody>
@forelse($logs as $log)<tr><td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td><td>{{ optional($log->school)->name ?: 'Platform' }}</td><td>{{ optional($log->actor)->name ?: 'System' }}</td><td>{{ $log->action }}</td><td>{{ $log->description }}</td><td>{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">No audit events found.</td></tr>@endforelse
</tbody></table></div><div class="card-footer">{{ $logs->links() }}</div></div>
@endsection

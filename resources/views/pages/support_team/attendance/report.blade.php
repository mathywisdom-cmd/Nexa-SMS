@extends('layouts.master')
@section('page_title', ucfirst($status).' attendance report')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3"><h4>{{ ucfirst($status) }} attendance report</h4><button class="btn btn-outline-secondary" onclick="window.print()">Print</button></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Student</th><th>Class / Section</th><th>Status</th><th>Remarks</th></tr></thead><tbody>@forelse($records as $record)<tr><td>{{ $record->session->attendance_date->format('Y-m-d') }}</td><td><a href="{{ route('attendance.student', $record->student_id) }}">{{ $record->student->name }}</a></td><td>{{ $record->session->myClass->name }} / {{ $record->session->section->name }}</td><td>{{ ucfirst($record->status) }}</td><td>{{ $record->remarks }}</td></tr>@empty<tr><td colspan="5" class="text-center">No matching attendance records.</td></tr>@endforelse</tbody></table></div><div class="card-footer">{{ $records->links() }}</div></div>
@endsection

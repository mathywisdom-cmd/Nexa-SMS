@extends('layouts.master')
@section('page_title', 'Attendance Sheet')
@section('content')
<div class="d-flex justify-content-between mb-3"><div><h5>{{ $attendanceSession->myClass->name }} — {{ $attendanceSession->section->name }}</h5><div>{{ $attendanceSession->attendance_date->format('F j, Y') }}</div></div><a class="btn btn-outline-primary" href="{{ route('attendance.print', $attendanceSession) }}">Printable sheet</a></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Student</th><th>Status</th><th>Remarks</th></tr></thead><tbody>@foreach($attendanceSession->records as $record)<tr><td>{{ optional($record->student)->name }}</td><td>{{ ucfirst($record->status) }}</td><td>{{ $record->remarks }}</td></tr>@endforeach</tbody></table></div></div>
@endsection

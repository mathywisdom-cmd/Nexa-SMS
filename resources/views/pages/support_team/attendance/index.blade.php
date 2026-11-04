@extends('layouts.master')
@section('page_title', 'Daily Attendance')
@section('content')
<div class="d-flex justify-content-end mb-3"><a class="btn btn-outline-primary" href="{{ route('attendance.history') }}">Attendance history and reports</a></div>
<div class="card"><div class="card-header"><h5>Select class, section and date</h5></div><div class="card-body"><form method="get" action="{{ route('attendance.index') }}"><div class="form-row">
<div class="form-group col-md-4"><label>Class</label><select class="form-control" name="class_id" required><option value="">Choose class</option>@foreach($sections->pluck('my_class')->unique('id') as $class)<option value="{{ $class->id }}" {{ (string)$classId === (string)$class->id ? 'selected' : '' }}>{{ $class->name }}</option>@endforeach</select></div>
<div class="form-group col-md-4"><label>Section</label><select class="form-control" name="section_id" required><option value="">Choose section</option>@foreach($sections as $section)<option value="{{ $section->id }}" {{ (string)$sectionId === (string)$section->id ? 'selected' : '' }}>{{ $section->my_class->name }} — {{ $section->name }}</option>@endforeach</select></div>
<div class="form-group col-md-3"><label>Date</label><input class="form-control" type="date" name="date" value="{{ $date }}" required></div><div class="form-group col-md-1 align-self-end"><button class="btn btn-primary">Load</button></div>
</div></form></div></div>
@if($students->isNotEmpty())
<div class="card"><div class="card-header"><h5>Mark attendance</h5></div><div class="card-body">
<form method="post" action="{{ route('attendance.store') }}">@csrf<input type="hidden" name="class_id" value="{{ $students->first()->my_class_id }}"><input type="hidden" name="section_id" value="{{ $sectionId }}"><input type="hidden" name="date" value="{{ $date }}">
<div class="form-row"><div class="form-group col-md-4"><label>Academic year (optional)</label><select name="academic_year_id" class="form-control"><option value="">Not selected</option>@foreach($years as $year)<option value="{{ $year->id }}" {{ (string)optional($session)->academic_year_id === (string)$year->id ? 'selected' : '' }}>{{ $year->name }}</option>@endforeach</select></div><div class="form-group col-md-4"><label>Term (optional)</label><select name="term_id" class="form-control"><option value="">Not selected</option>@foreach($years as $year)@foreach($year->terms as $term)<option value="{{ $term->id }}" {{ (string)optional($session)->term_id === (string)$term->id ? 'selected' : '' }}>{{ $year->name }} — {{ $term->name }}</option>@endforeach @endforeach</select></div></div>
<div class="table-responsive"><table class="table"><thead><tr><th>Student</th><th>Admission no.</th><th>Status</th><th>Remarks</th></tr></thead><tbody>
@foreach($students as $record)@php($savedRecord = $saved->get($record->user_id))<tr><td>{{ optional($record->user)->name }}</td><td>{{ $record->adm_no }}</td><td><select class="form-control" name="records[{{ $record->user_id }}][status]" required><option value="">Select status</option>@foreach(['present'=>'Present','absent'=>'Absent','late'=>'Late','excused'=>'Excused'] as $value=>$label)<option value="{{ $value }}" {{ optional($savedRecord)->status === $value ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></td><td><input class="form-control" name="records[{{ $record->user_id }}][remarks]" value="{{ optional($savedRecord)->remarks }}" maxlength="255"></td></tr>@endforeach
</tbody></table></div><button class="btn btn-primary">Save attendance</button>
</form></div></div>
@elseif($sectionId)<div class="alert alert-warning">No student roster was found for this class and section.</div>@endif
@endsection

@extends('layouts.master')
@section('page_title', 'Platform Dashboard')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h4 class="font-weight-semibold mb-1">NexaSMS by Mathias</h4>
        <div class="text-muted">Platform overview</div>
    </div>
    <span class="badge badge-light">Permanent totals</span>
</div>

<div class="row">
    @php
        $overviewCards = [
            ['Total Schools', $totalSchools, 'icon-office'],
            ['Active Schools', $activeSchools, 'icon-checkmark-circle'],
            ['Inactive Schools', $inactiveSchools, 'icon-pause'],
            ['Suspended Schools', $suspendedSchools, 'icon-blocked'],
            ['Total Students', $totalStudents, 'icon-users'],
            ['Total Teachers', $totalTeachers, 'icon-user-tie'],
            ['Total Staff', $totalStaff, 'icon-user'],
            ['Total Parents', $totalParents, 'icon-user-heart'],
            ['Trial Schools', $trialSchools, 'icon-clock']
        ];
    @endphp
    @foreach($overviewCards as $card)
        <div class="col-sm-6 col-xl-3 mb-3">
            <a href="{{ $canManageSchools ? route('owner.schools.index') : '#' }}" class="text-reset" @if(!$canManageSchools) aria-disabled="true" @endif>
                <div class="card h-100 mb-0">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="font-size-sm text-muted">{{ $card[0] }}</div>
                            <div class="font-size-h3 font-weight-semibold mb-0">{{ number_format($card[1]) }}</div>
                        </div>
                        <i class="{{ $card[2] }} icon-2x text-primary"></i>
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h6 class="card-title mb-1">Quick Actions</h6>
            <small class="text-muted">Available platform tools</small>
        </div>
    </div>
    <div class="card-body d-flex flex-wrap">
        @if($canManageSchools)
            <a class="btn btn-primary mr-2 mb-2" href="{{ route('owner.schools.create') }}"><i class="icon-plus2 mr-1"></i> Add School</a>
            <a class="btn btn-light mr-2 mb-2" href="{{ route('owner.schools.index') }}"><i class="icon-office mr-1"></i> View Schools</a>
        @endif
        @if($canViewAuditLogs)
            <a class="btn btn-light mr-2 mb-2" href="{{ route('owner.audit_logs.index') }}"><i class="icon-history mr-1"></i> View Audit Logs</a>
        @endif
        <a class="btn btn-light mb-2" href="{{ route('my_account') }}"><i class="icon-user mr-1"></i> Platform Profile</a>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h6 class="card-title mb-1">School Activity</h6>
            <small class="text-muted">Activity since {{ $periodStart->format('M j, Y') }} · Permanent totals above are not date-filtered</small>
        </div>
        <form method="get" action="{{ route('owner.dashboard') }}" class="form-inline mt-2 mt-md-0">
            <label for="activity-period" class="mr-2">Activity period</label>
            <select id="activity-period" name="period" class="form-control" onchange="this.form.submit()">
                @foreach($periods as $key => $label)
                    <option value="{{ $key }}" {{ $period === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="card-body">
        <div class="row text-center">
            <div class="col-6 col-lg-3 mb-3"><div class="text-muted">Schools registered</div><strong class="font-size-h4">{{ number_format($recentRegistrationsCount) }}</strong></div>
            <div class="col-6 col-lg-3 mb-3"><div class="text-muted">Schools activated</div><strong class="font-size-h4">{{ number_format($recentlyActivated) }}</strong></div>
            <div class="col-6 col-lg-3 mb-3"><div class="text-muted">Schools suspended</div><strong class="font-size-h4">{{ number_format($recentlySuspended) }}</strong></div>
            <div class="col-6 col-lg-3 mb-3"><div class="text-muted">Currently inactive</div><strong class="font-size-h4">{{ number_format($inactiveSchools) }}</strong></div>
        </div>
        <h6 class="mt-2">Most recently active schools <small class="text-muted">(based on recorded audit activity)</small></h6>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>School</th><th>Code</th><th>Last recorded activity</th></tr></thead>
                <tbody>
                @forelse($recentActiveSchools as $school)
                    <tr><td>{{ $school->name }}</td><td>{{ $school->code }}</td><td>{{ \Illuminate\Support\Carbon::parse($school->last_activity_at)->format('M j, Y H:i') }}</td></tr>
                @empty
                    <tr><td colspan="3" class="text-center text-muted">No recorded school activity for this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="card-title">School Status</h6></div>
            <div class="card-body">
                @foreach(['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $key => $label)
                    @php $value = $statusBreakdown[$key]; $percentage = $statusTotal ? min(100, round($value * 100 / $statusTotal)) : 0; @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between"><span>{{ $label }}</span><strong>{{ number_format($value) }}</strong></div>
                        <div class="progress" style="height: 8px"><div class="progress-bar {{ $key === 'active' ? 'bg-success' : ($key === 'suspended' ? 'bg-danger' : 'bg-warning') }}" role="progressbar" style="width: {{ $percentage }}%" aria-valuenow="{{ $percentage }}" aria-valuemin="0" aria-valuemax="100"></div></div>
                    </div>
                @endforeach
                <small class="text-muted">{{ number_format($trialSchools) }} trial {{ $trialSchools === 1 ? 'school' : 'schools' }} are shown separately.</small>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="card-title">Platform Alerts</h6></div>
            <div class="card-body">
                @forelse($alerts as $alert)
                    <div class="alert alert-{{ $alert['status'] }} py-2 mb-2">
                        <strong>{{ $alert['label'] }}:</strong> {{ number_format($alert['count']) }}
                        @if(!empty($alert['schools']))
                            <ul class="mb-0 mt-1 pl-4">
                                @foreach($alert['schools'] as $school)
                                    <li>{{ $school->name }} ({{ $school->code }})</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">No platform alerts.</p>
                @endforelse
                <small class="text-muted d-block mt-2">Setup completeness is not tracked by the current schema, so no setup alerts are inferred.</small>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div><h6 class="card-title mb-1">Recent School Registrations</h6><small class="text-muted">{{ $periodLabel }}</small></div>
        @if($canManageSchools)<a href="{{ route('owner.schools.index') }}">View all schools</a>@endif
    </div>
    <div class="table-responsive">
        <table class="table table-hover text-nowrap">
            <thead><tr><th>School</th><th>School Code</th><th>Status</th><th>Registration Date</th><th>Students</th><th>Teachers</th><th>Administrator</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($recentSchools as $school)
                <tr>
                    <td>{{ $school->name }}</td><td>{{ $school->code }}</td>
                    <td><span class="badge badge-light">{{ ucfirst($school->status) }}</span></td>
                    <td>{{ optional($school->created_at)->format('M j, Y') }}</td>
                    <td>{{ number_format($school->students_count) }}</td><td>{{ number_format($school->teachers_count) }}</td>
                    <td>{{ $school->administrator_name ?: 'No administrator' }}@if($school->administrator_email)<small class="d-block text-muted">{{ $school->administrator_email }}</small>@endif</td>
                    <td>@if($canManageSchools)<a class="btn btn-sm btn-light" href="{{ route('owner.schools.edit', $school) }}">View / Manage</a>@else<span class="text-muted">—</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted">No recent registrations for this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($canViewReports)
<div class="card">
    <div class="card-header"><h6 class="card-title">Schools by Student Population</h6><small class="text-muted">Administrative counts only</small></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>School</th><th>Students</th><th>Teachers</th><th>Parents</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($topSchools as $school)
                <tr><td>{{ $school->name }}</td><td>{{ number_format($school->students_count) }}</td><td>{{ number_format($school->teachers_count) }}</td><td>{{ number_format($school->parents_count) }}</td><td>{{ ucfirst($school->status) }}</td></tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted">No schools registered yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <h6 class="card-title mb-0">Recent Platform Activity</h6>
        @if($canViewAuditLogs)<a href="{{ route('owner.audit_logs.index') }}">View All Audit Logs</a>@endif
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Date / Time</th><th>User</th><th>Action</th><th>School</th><th>Description</th></tr></thead>
            <tbody>
            @forelse($recentActivity as $event)
                <tr><td>{{ optional($event->created_at)->format('M j, Y H:i') }}</td><td>{{ optional($event->actor)->name ?: 'System' }}</td><td>{{ $event->action }}</td><td>{{ optional($event->school)->name ?: 'Platform' }}</td><td>{{ $event->description ?: '—' }}</td></tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted">No recent platform activity.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="card-title">Platform Health</h6></div>
    <div class="card-body d-flex flex-wrap">
        <span class="badge {{ $databaseAvailable ? 'badge-success' : 'badge-danger' }} mr-2 mb-2">Database: {{ $databaseAvailable ? 'Connected' : 'Unavailable' }}</span>
        <span class="badge {{ $storageAvailable ? 'badge-success' : 'badge-warning' }} mr-2 mb-2">Local storage: {{ $storageAvailable ? 'Writable' : 'Not writable' }}</span>
    </div>
</div>
@endsection

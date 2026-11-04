<li class="nav-item">
    <a href="{{ route('school.settings') }}" class="nav-link {{ Route::is('school.settings*') ? 'active' : '' }}"><i class="icon-gear"></i><span>School Settings</span></a>
</li>
<li class="nav-item"><a href="{{ route('academic_periods.index') }}" class="nav-link {{ Route::is('academic_periods.*', 'academic_years.*', 'terms.*') ? 'active' : '' }}"><i class="icon-calendar"></i><span>Academic Years &amp; Terms</span></a></li>
<li class="nav-item"><a href="{{ route('attendance.index') }}" class="nav-link {{ Route::is('attendance.*') ? 'active' : '' }}"><i class="icon-checkmark-circle"></i><span>Attendance</span></a></li>

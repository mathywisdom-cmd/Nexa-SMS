{{--Manage Settings--}}
<li class="nav-item">
    <a href="{{ route('owner.dashboard') }}" class="nav-link {{ Route::is('owner.dashboard') ? 'active' : '' }}"><i class="icon-home4"></i> <span>Platform Dashboard</span></a>
</li>

<li class="nav-item"><a href="{{ route('owner.schools.index') }}" class="nav-link"><i class="icon-office"></i><span>Schools</span></a></li>
<li class="nav-item"><a href="{{ route('owner.permissions.index') }}" class="nav-link"><i class="icon-key"></i><span>Role permissions</span></a></li>
<li class="nav-item"><a href="{{ route('owner.audit_logs.index') }}" class="nav-link"><i class="icon-history"></i><span>Audit logs</span></a></li>

{{--Pins--}}
<li class="nav-item nav-item-submenu {{ in_array(Route::currentRouteName(), ['pins.create', 'pins.index']) ? 'nav-item-expanded nav-item-open' : '' }} ">
    <a href="#" class="nav-link"><i class="icon-lock2"></i> <span> Pins</span></a>

    <ul class="nav nav-group-sub" data-submenu-title="Manage Pins">
        {{--Generate Pins--}}
            <li class="nav-item">
                <a href="{{ route('pins.create') }}"
                   class="nav-link {{ (Route::is('pins.create')) ? 'active' : '' }}">Generate Pins</a>
            </li>

        {{--    Valid/Invalid Pins  --}}
        <li class="nav-item">
            <a href="{{ route('pins.index') }}"
               class="nav-link {{ (Route::is('pins.index')) ? 'active' : '' }}">View Pins</a>
        </li>
    </ul>
</li>

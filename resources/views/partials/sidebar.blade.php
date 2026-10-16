@php
    // Each group is hidden entirely when the user may not see any of its items.
    // Wording is plain on purpose: "Bills" and "Receipts" are what the front desk actually says.
    $groups = [
        ['title' => null, 'items' => [
            ['Dashboard', 'bi-grid-1x2', 'dashboard', 'dashboard', 'dashboard.view'],
        ]],
        ['title' => 'Front desk', 'items' => [
            ['Patients', 'bi-people', 'patients.index', 'patients.*', 'patients.view'],
            ['Appointments', 'bi-calendar-event', 'appointments.index', 'appointments.*', 'appointments.view'],
            ['Visits', 'bi-clipboard2-pulse', 'visits.index', 'visits.*', 'visits.view'],
            ['Bills', 'bi-receipt', 'invoices.index', 'invoices.*', 'invoices.view'],
            ['Receipts', 'bi-cash-stack', 'payments.index', 'payments.*', 'payments.collect'],
            ['Follow-ups', 'bi-telephone-outbound', 'follow-ups.index', 'follow-ups.*', 'followups.view'],
        ]],
        ['title' => 'Instalments', 'items' => [
            ['EMI Plans', 'bi-calendar2-check', 'emi-plans.index', 'emi-plans.index|emi-plans.create|emi-plans.show', 'emi.view'],
            ['EMI Dues', 'bi-alarm', 'emi-plans.dues', 'emi-plans.dues', 'emi.view'],
        ]],
        ['title' => 'Team', 'items' => [
            ['Attendance', 'bi-person-check', 'attendance.index', 'attendance.*', 'attendance.view'],
            ['Employees', 'bi-person-vcard', 'employees.index', 'employees.*', 'employees.manage'],
            ['Salary', 'bi-wallet2', 'salary-payments.index', 'salary-payments.*', 'salary.manage'],
        ]],
        ['title' => 'Insights', 'items' => [
            ['Reports', 'bi-bar-chart-line', 'reports.index', 'reports.*', 'reports.view'],
            ['Audit log', 'bi-shield-check', 'audit.index', 'audit.*', 'audit.view'],
        ]],
        ['title' => 'Setup', 'items' => [
            ['Departments', 'bi-diagram-3', 'departments.index', 'departments.*', 'masters.manage'],
            ['Doctors', 'bi-person-badge', 'doctors.index', 'doctors.*', 'masters.manage'],
            ['Services', 'bi-list-check', 'services.index', 'services.*', 'masters.manage'],
            ['Branches', 'bi-buildings', 'branches.index', 'branches.*', 'branches.manage'],
            ['Settings', 'bi-gear', 'settings.edit', 'settings.*', 'settings.manage'],
            ['Users', 'bi-person-gear', 'users.index', 'users.*', 'users.manage'],
        ]],
        ['title' => null, 'items' => [
            ['How it works', 'bi-life-preserver', 'help', 'help', 'dashboard.view'],
        ]],
    ];
@endphp
<aside class="sidebar" id="sidebar">
    <a href="{{ route('dashboard') }}" class="sidebar-brand">
        @if (logo_url())
            <img src="{{ logo_url() }}" alt="" class="brand-logo">
        @else
            <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
        @endif
        <span class="brand-name">{{ setting('hospital_name') }}</span>
    </a>

    <nav class="sidebar-nav" aria-label="Main">
        @foreach ($groups as $group)
            @php $visible = array_filter($group['items'], fn ($i) => auth()->user()->can($i[4]) && Route::has($i[2])); @endphp
            @if ($visible)
                @if ($group['title'])<div class="nav-heading">{{ $group['title'] }}</div>@endif
                @foreach ($visible as [$label, $icon, $route, $active])
                    <a href="{{ route($route) }}" title="{{ $label }}" class="nav-item-link {{ request()->routeIs(...explode('|', $active)) ? 'active' : '' }}">
                        <i class="bi {{ $icon }}"></i><span>{{ $label }}</span>
                    </a>
                @endforeach
            @endif
        @endforeach
    </nav>

    <div class="sidebar-foot">
        <button type="button" class="sidebar-pin" id="sidebarPin" title="Keep the menu open"><i class="bi bi-pin-angle"></i><span>Pin menu open</span></button>
    </div>
</aside>

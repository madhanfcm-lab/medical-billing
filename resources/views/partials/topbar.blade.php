@php $u = auth()->user(); @endphp
<header class="topbar">
    <button class="btn btn-light btn-icon d-lg-none" id="sidebarToggle" aria-label="Open menu"><i class="bi bi-list fs-5"></i></button>

    <div class="topbar-search">
        <select id="globalPatientSearch" class="form-select"
                data-url="{{ Route::has('patients.search') ? route('patients.search') : '' }}"
                data-profile-url="{{ Route::has('patients.show') ? route('patients.show', ['patient' => '__ID__']) : '' }}"
                data-register-url="{{ Route::has('patients.create') && $u->can('patients.manage') ? route('patients.create') : '' }}"
                data-placeholder="Find a patient: name, phone or ID"></select>
        <span class="search-hint d-none d-xl-block"><kbd>/</kbd></span>
    </div>

    <div class="ms-auto d-flex align-items-center gap-2">
        @if (isset($branchSwitcher) && $branchSwitcher['branches']->count() > 1)
        @php $cur = $branchSwitcher['current']; @endphp
        <div class="dropdown">
            <button class="btn branch-btn {{ $branchSwitcher['all'] ? 'is-all' : '' }}" data-bs-toggle="dropdown" aria-expanded="false" title="Switch branch">
                <i class="bi bi-buildings"></i>
                <span class="d-none d-sm-inline text-start lh-1"><span class="d-block branch-label">Branch</span><span class="d-block fw-semibold">{{ $cur?->name ?? 'All branches' }}</span></span>
                <i class="bi bi-chevron-expand small text-muted"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:240px">
                <li><h6 class="dropdown-header">Work in…</h6></li>
                @foreach ($branchSwitcher['branches'] as $b)
                    <li><form method="POST" action="{{ route('branch.switch') }}">@csrf<input type="hidden" name="branch" value="{{ $b->id }}">
                        <button class="dropdown-item d-flex justify-content-between align-items-center">{{ $b->name }}@if ($cur?->id === $b->id)<i class="bi bi-check2 text-success me-0 ms-3"></i>@endif</button></form></li>
                @endforeach
                <li><hr class="dropdown-divider"></li>
                <li><form method="POST" action="{{ route('branch.switch') }}">@csrf<input type="hidden" name="branch" value="all">
                    <button class="dropdown-item d-flex justify-content-between align-items-center"><span><i class="bi bi-collection"></i>All branches <span class="text-muted small">(view)</span></span>@if ($branchSwitcher['all'])<i class="bi bi-check2 text-success me-0 ms-3"></i>@endif</button></form></li>
            </ul>
        </div>
        @endif

        @canany(['patients.manage', 'invoices.create', 'visits.manage', 'appointments.manage', 'followups.manage', 'emi.manage', 'attendance.manage'])
        <div class="dropdown">
            <button class="btn btn-primary" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg me-1"></i><span class="d-none d-sm-inline">New</span></button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:230px">
                <li><h6 class="dropdown-header">Create</h6></li>
                @can('patients.manage')<li><a class="dropdown-item" href="{{ route('patients.create') }}"><i class="bi bi-person-plus"></i>Register patient</a></li>@endcan
                @can('invoices.create')<li><a class="dropdown-item" href="{{ route('invoices.create') }}"><i class="bi bi-receipt"></i>New bill</a></li>@endcan
                @can('visits.manage')<li><a class="dropdown-item" href="{{ route('visits.create') }}"><i class="bi bi-clipboard2-plus"></i>Record visit</a></li>@endcan
                @can('appointments.manage')<li><a class="dropdown-item" href="{{ route('appointments.create') }}"><i class="bi bi-calendar-plus"></i>Book appointment</a></li>@endcan
                @can('followups.manage')<li><a class="dropdown-item" href="{{ route('follow-ups.create') }}"><i class="bi bi-telephone-plus"></i>Set follow-up</a></li>@endcan
                @can('emi.manage')<li><a class="dropdown-item" href="{{ route('emi-plans.create') }}"><i class="bi bi-calendar2-plus"></i>New EMI plan</a></li>@endcan
                @can('attendance.manage')<li><hr class="dropdown-divider"><a class="dropdown-item" href="{{ route('attendance.index') }}"><i class="bi bi-person-check"></i>Take attendance</a></li>@endcan
            </ul>
        </div>
        @endcanany

        <span class="text-muted small d-none d-md-inline px-2"><i class="bi bi-calendar3 me-1"></i>{{ now()->format('D, d-m-Y') }}</span>

        <div class="dropdown">
            <button class="btn btn-light btn-icon" id="themePickerBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Switch display theme" aria-label="Theme appearance">
                <i class="bi bi-sun-fill theme-icon-active fs-6 text-warning"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end theme-menu-dropdown" style="min-width: 220px">
                <li><h6 class="dropdown-header d-flex justify-content-between align-items-center"><span>Appearance Theme</span><span class="badge bg-secondary text-muted" style="font-size:.65rem">Display</span></h6></li>
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="light">
                        <span class="d-flex align-items-center gap-2">
                            <span class="theme-swatch swatch-light"></span>
                            <span>Clinical Light</span>
                        </span>
                        <i class="bi bi-check2 text-primary theme-check d-none"></i>
                    </button>
                </li>
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="dark">
                        <span class="d-flex align-items-center gap-2">
                            <span class="theme-swatch swatch-dark"></span>
                            <span>Obsidian Dark</span>
                        </span>
                        <i class="bi bi-check2 text-primary theme-check d-none"></i>
                    </button>
                </li>
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="navy">
                        <span class="d-flex align-items-center gap-2">
                            <span class="theme-swatch swatch-navy"></span>
                            <span>Midnight Navy</span>
                        </span>
                        <i class="bi bi-check2 text-primary theme-check d-none"></i>
                    </button>
                </li>
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="emerald">
                        <span class="d-flex align-items-center gap-2">
                            <span class="theme-swatch swatch-emerald"></span>
                            <span>Clinical Jade</span>
                        </span>
                        <i class="bi bi-check2 text-primary theme-check d-none"></i>
                    </button>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="system">
                        <span class="d-flex align-items-center gap-2">
                            <i class="bi bi-display text-secondary"></i>
                            <span>Match System</span>
                        </span>
                        <i class="bi bi-check2 text-primary theme-check d-none"></i>
                    </button>
                </li>
            </ul>
        </div>

        <div class="dropdown">
            <button class="user-btn" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar">{{ strtoupper(substr($u->name, 0, 1)) }}</span>
                <span class="d-none d-md-block text-start lh-1 pe-1">
                    <span class="d-block fw-semibold small">{{ $u->name }}</span>
                    <span class="text-muted" style="font-size:.72rem">{{ \App\Enums\Role::tryFrom((string) $u->role_name)?->label() }}</span>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:200px">
                <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="bi bi-person"></i>My profile</a></li>
                <li><a class="dropdown-item" href="{{ route('help') }}"><i class="bi bi-life-preserver"></i>How it works</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right text-danger"></i>Log out</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>

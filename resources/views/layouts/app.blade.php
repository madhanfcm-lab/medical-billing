<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ setting('hospital_name') }}</title>
    @include('partials.assets-head')
    <script>try {
        if (localStorage.getItem('sidebarPinned') === '1') document.documentElement.classList.add('sidebar-pinned');
        // You just clicked a menu item: the new page cannot tell yet that the mouse is still over the menu, so keep it open until the mouse moves away.
        var t = parseInt(sessionStorage.getItem('sidebarKeepOpen') || '0', 10);
        if (t && Date.now() - t < 6000) document.documentElement.classList.add('sidebar-keepopen');
        sessionStorage.removeItem('sidebarKeepOpen');
    } catch (e) {}</script>
    @stack('styles')
</head>
<body>
<div class="app-shell">
    @include('partials.sidebar')
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="app-main">
        @include('partials.topbar')

        <main class="app-content">
            @if (isset($branchSwitcher) && $branchSwitcher['all'])
                <div class="alert alert-warning py-2 small d-flex align-items-center gap-2"><i class="bi bi-buildings"></i><span>You are viewing <strong>all branches</strong> together. Anything you create is saved in <strong>{{ app(\App\Support\CurrentBranch::class)->model()?->name }}</strong>; switch branch from the top bar to work in another one.</span></div>
            @endif
            @hasSection('hide-header')
            @else
                <div class="page-header">
                    <div>
                        <h1 class="page-title">@yield('title', 'Dashboard')</h1>
                        @hasSection('subtitle')<p class="page-sub">@yield('subtitle')</p>@endif
                        @hasSection('breadcrumbs')
                            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
                                @yield('breadcrumbs')
                            </ol></nav>
                        @endif
                    </div>
                    <div class="page-actions">@yield('actions')</div>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<form id="actionForm" method="POST" class="d-none">@csrf<input type="hidden" name="_method" value="DELETE"></form>

@include('partials.assets-scripts')
<script>
    {{-- the patient page shows its own green banner (with print links) for these, so no duplicate toast --}}
    @if (session('success') && ! session()->has('bill_id') && ! session()->has('batch_ref') && ! session('desk')) toastr.success(@json(session('success'))); @endif
    @if (session('error')) toastr.error(@json(session('error'))); @endif
    @if (session('info')) toastr.info(@json(session('info'))); @endif
</script>
@stack('scripts')
</body>
</html>

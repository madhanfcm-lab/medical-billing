<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sign in') · {{ setting('hospital_name') }}</title>
    @include('partials.assets-head')
</head>
<body>
<div class="auth-split">
    <aside class="auth-brand @if (login_image_url()) has-photo @endif" @if (login_image_url()) style="background-image: linear-gradient(160deg, rgba(8,40,44,.5), rgba(8,40,44,.82)), url('{{ login_image_url() }}');" @endif>
        <div class="d-flex align-items-center gap-3" style="position:relative;z-index:1">
            @if (logo_url())
                <img src="{{ logo_url() }}" alt="" class="brand-logo">
            @else
                <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
            @endif
            <span class="fw-semibold fs-5">{{ setting('hospital_name') }}</span>
        </div>
        <div style="position:relative;z-index:1">
            <h2 class="text-white fw-bold" style="font-size:2.1rem;letter-spacing:-.03em;line-height:1.2;max-width:15ch">One desk. One screen. Every patient.</h2>
            <p style="color:rgba(255,255,255,.75);max-width:42ch">Register, bill, take payment and book the next visit without leaving the patient's page.</p>
            <ul class="auth-points">
                <li><i class="bi bi-person-lines-fill"></i><span><strong class="text-white d-block">Everything on the patient page</strong>Visit, bill, payment, EMI and follow-up in one place.</span></li>
                <li><i class="bi bi-calendar2-check"></i><span><strong class="text-white d-block">Instalments made simple</strong>Flexible payments, automatic schedules, WhatsApp reminders.</span></li>
                <li><i class="bi bi-shield-check"></i><span><strong class="text-white d-block">Every rupee traceable</strong>Receipts, audit trail and daily collection reports.</span></li>
            </ul>
        </div>
        <div style="color:rgba(255,255,255,.5);font-size:.8rem;position:relative;z-index:1">&copy; {{ now()->year }} {{ setting('hospital_name') }}</div>
    </aside>
    <main class="auth-form-wrap position-relative">
        <div class="position-absolute top-0 end-0 p-3">
            <div class="dropdown">
                <button class="btn btn-sm btn-light btn-icon" id="guestThemePickerBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Switch appearance theme" aria-label="Theme appearance">
                    <i class="bi bi-sun-fill theme-icon-active fs-6 text-warning"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end theme-menu-dropdown shadow-md" style="min-width: 210px">
                    <li><h6 class="dropdown-header">Appearance Theme</h6></li>
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="light">
                            <span class="d-flex align-items-center gap-2"><span class="theme-swatch swatch-light"></span><span>Clinical Light</span></span>
                            <i class="bi bi-check2 text-primary theme-check d-none"></i>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="dark">
                            <span class="d-flex align-items-center gap-2"><span class="theme-swatch swatch-dark"></span><span>Obsidian Dark</span></span>
                            <i class="bi bi-check2 text-primary theme-check d-none"></i>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="navy">
                            <span class="d-flex align-items-center gap-2"><span class="theme-swatch swatch-navy"></span><span>Midnight Navy</span></span>
                            <i class="bi bi-check2 text-primary theme-check d-none"></i>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="emerald">
                            <span class="d-flex align-items-center gap-2"><span class="theme-swatch swatch-emerald"></span><span>Clinical Jade</span></span>
                            <i class="bi bi-check2 text-primary theme-check d-none"></i>
                        </button>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center justify-content-between js-theme-pick" data-theme-val="system">
                            <span class="d-flex align-items-center gap-2"><i class="bi bi-display text-secondary"></i><span>Match System</span></span>
                            <i class="bi bi-check2 text-primary theme-check d-none"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
        <div class="auth-form">
            @yield('content')
        </div>
    </main>
</div>
@include('partials.assets-scripts')
</body>
</html>

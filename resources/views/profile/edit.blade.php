@extends('layouts.app')
@section('title', 'My Profile')
@section('breadcrumbs')<li class="breadcrumb-item active">My Profile</li>@endsection

@section('content')
<div class="row g-4">
    <div class="col-lg-6">
        <form method="POST" action="{{ route('profile.update') }}" class="card" novalidate data-loading-form>
            @csrf @method('PATCH')
            <div class="card-header">Profile information</div>
            <div class="card-body vstack gap-3">
                <div>@include('partials.field', ['name' => 'name', 'label' => 'Name', 'value' => $user->name, 'required' => true])</div>
                <div>@include('partials.field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'value' => $user->email, 'required' => true])</div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
    <div class="col-lg-6">
        <form method="POST" action="{{ route('password.update') }}" class="card" novalidate data-loading-form>
            @csrf @method('PUT')
            <div class="card-header">Change password</div>
            <div class="card-body vstack gap-3">
                <div>@include('partials.field', ['name' => 'current_password', 'bag' => 'updatePassword', 'label' => 'Current password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])</div>
                <div>@include('partials.field', ['name' => 'password', 'bag' => 'updatePassword', 'label' => 'New password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => 'Minimum 8 characters.'])</div>
                <div>@include('partials.field', ['name' => 'password_confirmation', 'bag' => 'updatePassword', 'label' => 'Confirm new password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])</div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary">Update password</button></div>
        </form>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-palette me-2 text-primary"></i>Appearance & Display Themes</span>
                <span class="small text-muted fw-normal">Instantly saved on this device</span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Choose the visual display theme tailored for your clinical environment. High-contrast medical dark modes prevent ocular fatigue during long diagnostic, consult, and billing shifts.</p>

                <div class="row g-3">
                    <div class="col-sm-6 col-xl-3">
                        <div class="theme-card-option js-theme-card" data-theme-val="light" role="button" tabindex="0">
                            <div class="theme-preview-box preview-light">
                                <div class="prev-sidebar"></div>
                                <div class="prev-body">
                                    <div class="prev-topbar"></div>
                                    <div class="prev-card">
                                        <div class="prev-line w-75"></div>
                                        <div class="prev-line w-50"></div>
                                        <div class="prev-badge"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mt-2 pt-1">
                                <div>
                                    <div class="fw-semibold small">Clinical Light</div>
                                    <div class="text-muted" style="font-size:.72rem">Crisp hospital daylight & slate</div>
                                </div>
                                <span class="theme-check-pill"><i class="bi bi-check-lg"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="theme-card-option js-theme-card" data-theme-val="dark" role="button" tabindex="0">
                            <div class="theme-preview-box preview-dark">
                                <div class="prev-sidebar"></div>
                                <div class="prev-body">
                                    <div class="prev-topbar"></div>
                                    <div class="prev-card">
                                        <div class="prev-line w-75"></div>
                                        <div class="prev-line w-50"></div>
                                        <div class="prev-badge"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mt-2 pt-1">
                                <div>
                                    <div class="fw-semibold small">Obsidian Dark</div>
                                    <div class="text-muted" style="font-size:.72rem">Calibrated slate & low glare teal</div>
                                </div>
                                <span class="theme-check-pill"><i class="bi bi-check-lg"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="theme-card-option js-theme-card" data-theme-val="navy" role="button" tabindex="0">
                            <div class="theme-preview-box preview-navy">
                                <div class="prev-sidebar"></div>
                                <div class="prev-body">
                                    <div class="prev-topbar"></div>
                                    <div class="prev-card">
                                        <div class="prev-line w-75"></div>
                                        <div class="prev-line w-50"></div>
                                        <div class="prev-badge"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mt-2 pt-1">
                                <div>
                                    <div class="fw-semibold small">Midnight Navy</div>
                                    <div class="text-muted" style="font-size:.72rem">Deep oceanic telemetry blue</div>
                                </div>
                                <span class="theme-check-pill"><i class="bi bi-check-lg"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="theme-card-option js-theme-card" data-theme-val="emerald" role="button" tabindex="0">
                            <div class="theme-preview-box preview-emerald">
                                <div class="prev-sidebar"></div>
                                <div class="prev-body">
                                    <div class="prev-topbar"></div>
                                    <div class="prev-card">
                                        <div class="prev-line w-75"></div>
                                        <div class="prev-line w-50"></div>
                                        <div class="prev-badge"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mt-2 pt-1">
                                <div>
                                    <div class="fw-semibold small">Clinical Jade</div>
                                    <div class="text-muted" style="font-size:.72rem">Therapeutic evergreen night</div>
                                </div>
                                <span class="theme-check-pill"><i class="bi bi-check-lg"></i></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between p-3 border rounded-3 mt-3 bg-body-tertiary">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-display fs-4 text-muted"></i>
                        <div>
                            <div class="fw-semibold small">Automatic System Sync</div>
                            <div class="text-muted" style="font-size:.75rem">Automatically synchronize appearance with your device's operating system setting.</div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary js-theme-pick" data-theme-val="system">
                        <span class="d-flex align-items-center gap-1"><i class="bi bi-arrow-repeat"></i><span>Sync with OS</span></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

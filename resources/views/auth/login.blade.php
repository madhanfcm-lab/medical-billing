@extends('layouts.guest')

@section('content')
<div class="mb-4">
    <h1 class="h3 fw-bold mb-1" style="letter-spacing:-.02em">Welcome back</h1>
    <p class="text-muted mb-0">Sign in to open today's desk.</p>
</div>
<form method="POST" action="{{ route('login') }}" class="needs-validation" novalidate data-loading-form>
    @csrf
    <div class="mb-3">
        @include('partials.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'username', 'msg' => 'Enter a valid email address.', 'placeholder' => 'you@clinic.com'])
    </div>
    <div class="mb-3">
        @include('partials.field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'placeholder' => '••••••••'])
    </div>
    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" name="remember" id="remember">
        <label class="form-check-label" for="remember">Keep me signed in on this computer</label>
    </div>
    <button type="submit" class="btn btn-primary btn-lg w-100">Sign in</button>
</form>
@endsection

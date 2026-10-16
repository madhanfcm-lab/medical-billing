@extends('layouts.app')
@section('title', 'Reports')
@section('breadcrumbs')<li class="breadcrumb-item active">Reports</li>@endsection

@section('content')
<div class="row g-3">
    @foreach ($reports as $key => $r)
        <div class="col-md-6 col-xl-4">
            <a href="{{ route('reports.show', $key) }}" class="card h-100 text-decoration-none text-body report-card">
                <div class="card-body d-flex gap-3">
                    <span class="stat-icon bg-primary-subtle text-primary flex-shrink-0"><i class="bi {{ $r->icon() }}"></i></span>
                    <div>
                        <div class="fw-semibold fs-6">{{ $r->title() }}</div>
                        <div class="small text-muted">{{ $r->description() }}</div>
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>
@endsection

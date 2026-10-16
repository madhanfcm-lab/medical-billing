{{-- Audit trail for one record (admin only). Pass $logs: the record's activities with user loaded. --}}
<div class="card mt-3">
    <div class="card-header"><i class="bi bi-shield-check me-1"></i>Audit trail</div>
    @if ($logs->isEmpty())
        <div class="card-body small text-muted">No recorded changes.</div>
    @else
        <div class="list-group list-group-flush">
            @foreach ($logs as $log)
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <span><span class="badge {{ $log->event_badge }}">{{ ucfirst($log->event) }}</span> <span class="small">{{ $log->user?->name ?? 'System' }}</span></span>
                        <span class="small text-muted">{{ format_datetime($log->created_at) }}</span>
                    </div>
                    <div class="mt-1">@include('audit._changes', ['log' => $log])</div>
                </div>
            @endforeach
        </div>
    @endif
</div>

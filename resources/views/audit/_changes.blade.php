@php
    $old = $log->old_values ?? [];
    $new = $log->new_values ?? [];
    $fields = array_keys($new + $old);
@endphp
@if ($fields === [])
    <span class="text-muted">{{ $log->event === 'deleted' ? 'Record removed' : '—' }}</span>
@else
    <details>
        <summary class="small text-primary" style="cursor:pointer">{{ count($fields) }} {{ \Illuminate\Support\Str::plural('field', count($fields)) }}{{ $log->event === 'created' ? '' : ' changed' }}</summary>
        <table class="table table-sm table-borderless mb-0 mt-1 small audit-changes">
            <thead><tr class="text-muted"><th>Field</th>@if ($log->event !== 'created')<th>Before</th>@endif<th>{{ $log->event === 'created' ? 'Value' : 'After' }}</th></tr></thead>
            <tbody>
            @foreach ($fields as $f)
                <tr>
                    <td class="text-muted">{{ \Illuminate\Support\Str::headline($f) }}</td>
                    @if ($log->event !== 'created')<td class="text-danger">{{ \App\Models\ActivityLog::formatValue($f, $old[$f] ?? null) }}</td>@endif
                    <td class="text-success">{{ \App\Models\ActivityLog::formatValue($f, $new[$f] ?? null) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </details>
@endif

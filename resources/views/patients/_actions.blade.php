<a href="{{ route('patients.show', $patient) }}" class="btn btn-sm btn-outline-secondary" title="Open profile"><i class="bi bi-eye"></i></a>
@can('patients.manage')
    <a href="{{ route('patients.edit', $patient) }}" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
@endcan

<a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
@if (! $user->is(auth()->user()))
    <button type="button" class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }} js-toggle"
            data-url="{{ route('users.toggle', $user) }}"
            data-title="{{ $user->is_active ? 'Deactivate user?' : 'Activate user?' }}"
            data-text="{{ $user->is_active ? e($user->name).' will no longer be able to sign in.' : e($user->name).' will be able to sign in again.' }}"
            title="{{ $user->is_active ? 'Deactivate' : 'Activate' }}">
        <i class="bi {{ $user->is_active ? 'bi-person-x' : 'bi-person-check' }}"></i>
    </button>
@endif

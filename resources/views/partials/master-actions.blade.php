<a href="{{ $edit }}" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
<button type="button" class="btn btn-sm btn-outline-danger js-action" title="Delete"
        data-url="{{ $delete }}" data-method="DELETE"
        data-title="Delete {{ e($label) }}?" data-text="It will be hidden from lists. Existing records that use it are not affected.">
    <i class="bi bi-trash"></i>
</button>

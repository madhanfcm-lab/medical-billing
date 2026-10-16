{{-- One picture picker: preview, choose a file to upload or replace, or tick Remove. --}}
<div class="img-field">
    <label for="{{ $field }}" class="form-label">{{ $title }}</label>
    <div class="d-flex gap-3 align-items-center flex-wrap p-3 border rounded-3 bg-light">
        <div class="d-flex align-items-center justify-content-center bg-white border rounded-3" style="width:{{ $boxWidth ?? $box }};height:{{ $box }};overflow:hidden;flex-shrink:0">
            <img src="{{ $url }}" class="js-img-preview @unless ($url) d-none @endunless" alt="{{ $title }}" style="max-width:100%;max-height:100%">
            <i class="bi bi-image text-muted fs-2 js-img-placeholder @if ($url) d-none @endif"></i>
        </div>
        <div class="flex-fill" style="min-width:200px">
            <input type="file" name="{{ $field }}" id="{{ $field }}" accept="{{ $accept }}" class="form-control js-img-input @error($field) is-invalid @enderror">
            <div class="invalid-feedback">{{ $errors->first($field) }}</div>
            <div class="form-text">{{ $help }}</div>
            @if ($url)
                <div class="form-check mt-2"><input class="form-check-input js-img-remove" type="checkbox" name="{{ $remove }}" id="{{ $remove }}" value="1"><label class="form-check-label small" for="{{ $remove }}">Remove the current picture</label></div>
            @endif
        </div>
    </div>
</div>

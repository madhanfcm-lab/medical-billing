@extends('layouts.app')
@section('title', 'Settings')
@section('breadcrumbs')<li class="breadcrumb-item active">Settings</li>@endsection

@section('content')
<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" novalidate data-loading-form>
    @csrf @method('PUT')
    <div class="card mb-4">
        <div class="card-header">Branding: pictures</div>
        <div class="card-body row g-4">
            <div class="col-lg-6">
                @include('settings._image-field', ['field' => 'hospital_logo', 'remove' => 'remove_logo', 'title' => 'Clinic logo', 'url' => logo_url(), 'box' => '84px', 'accept' => 'image/png,image/jpeg,image/webp',
                    'help' => 'PNG, JPG or WebP, up to 1 MB. A square image works best. Shown in the menu, on the login page and on every printed bill and receipt.'])
            </div>
            <div class="col-lg-6">
                @include('settings._image-field', ['field' => 'login_image', 'remove' => 'remove_login_image', 'title' => 'Login page picture', 'url' => login_image_url(), 'box' => '84px', 'boxWidth' => '150px', 'accept' => 'image/png,image/jpeg,image/webp',
                    'help' => 'PNG, JPG or WebP, up to 3 MB. A wide photo of your clinic works well. It fills the left side of the login page. Without one, the default colour panel is used.'])
            </div>
        </div>
    </div>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">Hospital / clinic details</div>
                <div class="card-body row g-3">
                    <div class="col-12">@include('partials.field', ['name' => 'hospital_name', 'label' => 'Name', 'value' => $values['hospital_name'], 'required' => true, 'maxlength' => 150])</div>
                    <div class="col-12">
                        <label for="hospital_address" class="form-label">Address</label>
                        <textarea name="hospital_address" id="hospital_address" rows="2" maxlength="500"
                                  class="form-control @error('hospital_address') is-invalid @enderror">{{ old('hospital_address', $values['hospital_address']) }}</textarea>
                        <div class="invalid-feedback">{{ $errors->first('hospital_address') }}</div>
                    </div>
                    <div class="col-md-6">@include('partials.field', ['name' => 'hospital_phone', 'label' => 'Phone', 'value' => $values['hospital_phone'], 'maxlength' => 20])</div>
                    <div class="col-md-6">@include('partials.field', ['name' => 'hospital_email', 'label' => 'E-mail', 'type' => 'email', 'value' => $values['hospital_email']])</div>
                    <div class="col-md-6">@include('partials.field', ['name' => 'hospital_gstin', 'label' => 'GSTIN', 'value' => $values['hospital_gstin'], 'maxlength' => 15, 'help' => 'Leave blank if not GST registered.'])</div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header">Numbering prefixes</div>
                <div class="card-body row g-3">
                    <div class="col-4">@include('partials.field', ['name' => 'invoice_prefix', 'label' => 'Invoice', 'value' => $values['invoice_prefix'], 'required' => true, 'maxlength' => 10])</div>
                    <div class="col-4">@include('partials.field', ['name' => 'receipt_prefix', 'label' => 'Receipt', 'value' => $values['receipt_prefix'], 'required' => true, 'maxlength' => 10])</div>
                    <div class="col-4">@include('partials.field', ['name' => 'patient_prefix', 'label' => 'Patient ID', 'value' => $values['patient_prefix'], 'required' => true, 'maxlength' => 10])</div>
                    <div class="col-12 form-text">
                        Example: <code>{{ $values['invoice_prefix'] }}-{{ now()->year }}-00001</code>.
                        Changing a prefix only affects new numbers; numbers already issued are never reused or changed.
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">Invoice footer note</div>
                <div class="card-body">
                    <textarea name="invoice_footer" rows="3" maxlength="500"
                              class="form-control @error('invoice_footer') is-invalid @enderror">{{ old('invoice_footer', $values['invoice_footer']) }}</textarea>
                    <div class="invalid-feedback">{{ $errors->first('invoice_footer') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="mt-4 text-end"><button class="btn btn-primary px-4">Save settings</button></div>
</form>
@endsection

@push('scripts')
<script>
// show a chosen picture straight away, before saving
$('.js-img-input').on('change', function () {
    const f = this.files && this.files[0];
    if (!f) return;
    const $box = $(this).closest('.img-field');
    $box.find('.js-img-preview').attr('src', URL.createObjectURL(f)).removeClass('d-none');
    $box.find('.js-img-placeholder').addClass('d-none');
    $box.find('.js-img-remove').prop('checked', false);
});
</script>
@endpush

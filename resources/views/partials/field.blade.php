{{-- Usage: @include('partials.field', ['name'=>'x','label'=>'X','type'=>'text','value'=>$v,'required'=>true,'help'=>'..']) --}}
@php
    $type = $type ?? 'text';
    $id = $id ?? $name;
    $bag = $bag ?? 'default';
    $err = $errors->getBag($bag)->first($name);
    $val = $type === 'password' ? '' : old($name, $value ?? '');
@endphp
<label for="{{ $id }}" class="form-label">{{ $label }}@if (!empty($required)) <span class="text-danger">*</span>@endif</label>
<input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}" value="{{ $val }}"
       class="form-control @if($err) is-invalid @endif" @if(!empty($required)) required @endif
       @if(!empty($maxlength)) maxlength="{{ $maxlength }}" @endif
       @if(!empty($pattern)) pattern="{{ $pattern }}" @endif
       @if(!empty($placeholder)) placeholder="{{ $placeholder }}" @endif
       @if(!empty($autocomplete)) autocomplete="{{ $autocomplete }}" @endif>
<div class="invalid-feedback">{{ $err ?: ($msg ?? 'This field is required.') }}</div>
@if (!empty($help)) <div class="form-text">{{ $help }}</div> @endif

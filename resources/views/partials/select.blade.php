{{-- Usage: @include('partials.select', ['name'=>'x','label'=>'X','options'=>[value=>label],'value'=>$v,'required'=>true,'placeholder'=>'Select…']) --}}
@php
    $id = $id ?? $name;
    $err = $errors->first($name);
    $current = (string) old($name, $value ?? '');
@endphp
<label for="{{ $id }}" class="form-label">{{ $label }}@if (!empty($required)) <span class="text-danger">*</span>@endif</label>
<select name="{{ $name }}" id="{{ $id }}" class="form-select @if($err) is-invalid @endif" @if(!empty($required)) required @endif>
    <option value="">{{ $placeholder ?? 'Select…' }}</option>
    @foreach ($options as $optValue => $optLabel)
        <option value="{{ $optValue }}" @selected($current !== '' && $current === (string) $optValue)>{{ $optLabel }}</option>
    @endforeach
</select>
<div class="invalid-feedback">{{ $err ?: ($msg ?? 'Please make a selection.') }}</div>

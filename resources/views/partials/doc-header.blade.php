@php
    $logo = setting('hospital_logo');
    // Each branch can have its own address and phone on its documents; the hospital's are the fallback.
    $branch = $branch ?? app(\App\Support\CurrentBranch::class)->model();
    $address = $branch?->address ?: setting('hospital_address');
    $phone = $branch?->phone ?: setting('hospital_phone');
    $email = $branch?->email ?: setting('hospital_email');
    $showBranch = $branch && \App\Models\Branch::query()->count() > 1;
@endphp
<table class="hdr"><tr>
    <td style="width:60%">
        @if ($logo)
            <img src="{{ $pdf ? \Illuminate\Support\Facades\Storage::disk('public')->path($logo) : logo_url() }}" alt="" style="max-height:48px; max-width:140px; float:left; margin-right:10px;">
        @endif
        <div class="clinic">{{ setting('hospital_name') }}</div>
        @if ($showBranch)<div class="muted"><strong>{{ $branch->name }}</strong></div>@endif
        @if ($address)<div class="muted">{!! nl2br(e($address)) !!}</div>@endif
        <div class="muted">
            @if ($phone)Phone: {{ $phone }} @endif
            @if ($email) · {{ $email }} @endif
        </div>
        @if (setting('hospital_gstin'))<div class="muted">GSTIN: {{ setting('hospital_gstin') }}</div>@endif
    </td>
    <td style="width:40%" class="title">{{ $docTitle }}<div style="font-size:11px; color:#1d2b36; letter-spacing:0; font-weight:normal">{{ $docMeta }}</div></td>
</tr></table>
<hr style="border:0; border-top:2px solid #0f8b8d; margin:10px 0 12px">

@include('emails._layout-start', ['title' => __('password_reset.code_subject', ['code' => $code])])
<p style="margin:0 0 16px;">{{ __('password_reset.code_greeting', ['name' => $firstName]) }}</p>
@if ($socialProvider)
<p style="margin:0 0 16px; padding:12px 14px; border-radius:12px; background-color:#F4F1FF; color:#3A20B8; font-size:14px;">{{ __('password_reset.code_social', ['provider' => $socialProvider]) }}</p>
@endif
<p style="margin:0 0 8px;">{{ __('password_reset.code_intro') }}</p>
<p style="margin:24px 0; font-size:36px; font-weight:800; letter-spacing:10px; text-align:center; color:#3A20B8;">{{ $code }}</p>
<p style="margin:0 0 12px; font-size:14px; color:#4B4B5C;">{{ __('password_reset.code_validity', ['minutes' => $validMinutes]) }}</p>
<p style="margin:0; font-size:14px; color:#6B6B7B;">{{ __('password_reset.code_ignore') }}</p>
@include('emails._layout-end')

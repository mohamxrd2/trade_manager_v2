@include('emails._layout-start', ['title' => __('password_reset.changed_subject')])
<p style="margin:0 0 16px;">{{ __('password_reset.code_greeting', ['name' => $firstName]) }}</p>
<p style="margin:0 0 16px;">{{ __('password_reset.changed_body', ['date' => $date, 'time' => $time]) }}</p>
<p style="margin:0; padding:12px 14px; border-radius:12px; background-color:#FDE8EC; color:#A3213A; font-size:14px; font-weight:600;">{{ __('password_reset.changed_warning') }}</p>
@include('emails._layout-end')

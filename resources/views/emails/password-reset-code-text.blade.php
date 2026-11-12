{{ __('password_reset.code_greeting', ['name' => $firstName]) }}

@if ($socialProvider)
{{ __('password_reset.code_social', ['provider' => $socialProvider]) }}

@endif
{{ __('password_reset.code_intro') }}

{{ $code }}

{{ __('password_reset.code_validity', ['minutes' => $validMinutes]) }}
{{ __('password_reset.code_ignore') }}

Waelly

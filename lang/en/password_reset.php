<?php

return [
    'sent' => 'If an account exists for this email, a code has just been sent to it.',
    'too_many_requests' => 'Too many requests. Try again in a few minutes.',
    'code_expired' => 'This code has expired. Request a new one.',
    'code_invalid' => 'Incorrect code.',
    'too_many_attempts' => 'Too many incorrect attempts. Request a new code.',
    'token_invalid' => 'This request has expired. Start again from “Forgot password”.',
    'reset_done' => 'Password changed.',

    'code_subject' => 'Your Waelly code: :code',
    'code_greeting' => 'Hello :name,',
    'code_intro' => 'Here is your code to choose a new password:',
    'code_validity' => 'This code is valid for :minutes minutes.',
    'code_ignore' => "If you didn't request this code, ignore this email: your password won't change.",
    'code_social' => 'Your account was created with :provider: you can keep signing in with the “Continue with :provider” button. If you also want a password, use the code below.',

    'changed_subject' => 'Your Waelly password was changed',
    'changed_body' => 'Your Waelly password was changed on :date at :time.',
    'changed_warning' => "If this wasn't you, contact us immediately by replying to this email.",
    'date_format' => 'm/d/Y',
    'time_format' => 'g:i A',
];

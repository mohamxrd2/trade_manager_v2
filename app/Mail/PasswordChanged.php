<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/** Avertit le titulaire que son mot de passe vient d'être changé. */
class PasswordChanged extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $firstName,
        public Carbon $changedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('password_reset.changed_subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-changed',
            text: 'emails.password-changed-text',
            with: [
                'date' => $this->changedAt->format(__('password_reset.date_format')),
                'time' => $this->changedAt->format(__('password_reset.time_format')),
            ],
        );
    }
}

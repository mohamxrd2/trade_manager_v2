<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Code de réinitialisation du mot de passe. Le code est dans l'objet pour
 * être lisible depuis la notification du téléphone. `socialProvider` est
 * renseigné pour un compte créé avec Google/Facebook : l'e-mail rappelle
 * qu'il peut s'y connecter ainsi. La langue est posée par l'appelant
 * (->locale()).
 */
class PasswordResetCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $firstName,
        public int $validMinutes,
        public ?string $socialProvider = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('password_reset.code_subject', ['code' => $this->code]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset-code',
            text: 'emails.password-reset-code-text',
        );
    }
}

<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EmailVerificationCodeMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly int $minutes,
        public readonly string $firstName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your PharmAssist email verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.email-verification-code',
        );
    }
}
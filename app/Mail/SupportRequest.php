<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SupportRequest extends Mailable
{
    public function __construct(
        public string $reference,
        public string $senderName,
        public string $senderEmail,
        public string $topic,
        public string $requestBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->senderEmail, $this->senderName)],
            subject: "[InYice {$this->reference}] {$this->topic}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.support-request');
    }
}

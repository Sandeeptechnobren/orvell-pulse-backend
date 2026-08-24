<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;
    public string $type;
    public string $appName;

    /**
     * Create a new message instance.
     */
    public function __construct(string $otp, string $type = 'register', string $appName = 'ORVELL PULSE')
    {
        $this->otp = $otp;
        $this->type = $type;
        $this->appName = $appName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->type === 'reset'
            ? "[{$this->appName}] Password Reset Verification Code"
            : "[{$this->appName}] Your Email Verification Code";

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'otp'     => $this->otp,
                'type'    => $this->type,
                'appName' => $this->appName,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}

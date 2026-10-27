<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BonusEstimationReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public array $breakdown;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, array $breakdown)
    {
        $this->user = $user;
        $this->breakdown = $breakdown;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Estimated Bonus Structure Review - Khulna Agricultural University',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.bonus-estimation-review',
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

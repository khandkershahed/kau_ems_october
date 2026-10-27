<?php

namespace App\Mail;

use App\Models\BonusGenerate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BonusBillReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public BonusGenerate $bonusBill;

    /**
     * Create a new message instance.
     */
    public function __construct(BonusGenerate $bonusBill)
    {
        $this->bonusBill = $bonusBill;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $monthName = date('F', mktime(0, 0, 0, (int) $this->bonusBill->monthId, 1));
        $title = $this->bonusBill->bonus_title ?: 'Festival Bonus';
        return new Envelope(
            subject: "Bonus Bill ({$title}) Notification - {$monthName} {$this->bonusBill->bonusGenerationYear}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.bonus-bill-review',
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

<?php

namespace App\Mail;

use App\Models\SalaryGenerate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalaryBillReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public SalaryGenerate $salaryGenerate;

    /**
     * Create a new message instance.
     */
    public function __construct(SalaryGenerate $salaryGenerate)
    {
        $this->salaryGenerate = $salaryGenerate;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $monthName = date('F', mktime(0, 0, 0, (int) $this->salaryGenerate->monthId, 10));
        $year = $this->salaryGenerate->salaryGenerationYear;

        return new Envelope(
            subject: "Salary Bill Review - {$monthName} {$year} - Khulna Agricultural University",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.salary-bill-review',
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

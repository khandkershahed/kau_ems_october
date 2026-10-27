<?php
namespace App\Mail;
use App\Models\SalaryFixation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalaryFixationReviewMail extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public SalaryFixation $fixation) {}
    public function envelope(): Envelope
    {
        return new Envelope(subject: '2026 Salary Fixation — Personal Review Required — KAU');
    }
    public function content(): Content
    {
        return new Content(view: 'emails.salary-fixation-review');
    }
    public function attachments(): array { return []; }
}

<?php

namespace App\Mail;

use App\Models\DonationPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// ShouldQueue: the webhook returns to Stripe immediately; a queue worker sends the email.
class DonationReceipt extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public DonationPayment $payment)
    {
        $this->afterCommit();   // don't send if the webhook transaction rolls back
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Thank you — your donation receipt #'.$this->payment->id);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.donation-receipt',
            with: [
                'donation' => $this->payment->donation,
                'amount' => number_format($this->payment->amount / 100, 2),
            ],
        );
    }
}

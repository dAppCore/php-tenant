<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Mail;

use Core\Mod\Tenant\Models\AccountDeletionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountDeletionRequested extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public AccountDeletionRequest $deletionRequest
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm Your Account Deletion Request',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'tenant::emails.account-deletion-requested',
            with: [
                'user' => $this->deletionRequest->user,
                'confirmationUrl' => $this->deletionRequest->confirmationUrl(),
                'cancelUrl' => $this->deletionRequest->cancelUrl(),
                'expiresAt' => $this->deletionRequest->expires_at,
                'daysRemaining' => $this->deletionRequest->daysRemaining(),
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

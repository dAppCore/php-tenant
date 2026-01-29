<?php

declare(strict_types=1);

namespace Core\Tenant\Notifications;

use Core\Tenant\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  WorkspaceInvitation  $invitation  The invitation model
     * @param  string  $plaintextToken  The plaintext token for the URL (tokens are hashed in DB)
     */
    public function __construct(
        protected WorkspaceInvitation $invitation,
        protected string $plaintextToken
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Use the plaintext token for the URL (not the hashed one from the DB)
        $acceptUrl = route('workspace.invitation.accept', ['token' => $this->plaintextToken]);
        $workspaceName = $this->invitation->workspace->name;
        $inviterName = $this->invitation->inviter?->name ?? 'A team member';
        $roleName = ucfirst($this->invitation->role);
        $expiresAt = $this->invitation->expires_at->format('j F Y');

        return (new MailMessage)
            ->subject("You've been invited to join {$workspaceName}")
            ->greeting('Hello,')
            ->line("{$inviterName} has invited you to join **{$workspaceName}** as a **{$roleName}**.")
            ->action('Accept invitation', $acceptUrl)
            ->line("This invitation will expire on {$expiresAt}.")
            ->line('If you did not expect this invitation, you can safely ignore this email.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'workspace_invitation',
            'workspace_id' => $this->invitation->workspace_id,
            'workspace_name' => $this->invitation->workspace->name,
            'role' => $this->invitation->role,
        ];
    }
}

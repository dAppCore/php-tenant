<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Notifications;

use Core\Mod\Tenant\Models\Feature;
use Core\Mod\Tenant\Models\UsageAlertHistory;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent when a workspace approaches entitlement limits.
 */
class UsageAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Workspace $workspace,
        protected Feature $feature,
        protected int $threshold,
        protected int $used,
        protected int $limit
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
        $percentage = round(($this->used / $this->limit) * 100);
        $remaining = max(0, $this->limit - $this->used);
        $featureName = $this->feature->name;
        $workspaceName = $this->workspace->name;
        $appName = config('core.app.name', 'Host UK');

        $message = (new MailMessage)
            ->subject($this->getSubject($featureName, $percentage));

        if ($this->threshold === UsageAlertHistory::THRESHOLD_LIMIT) {
            return $this->limitReachedEmail($message, $featureName, $workspaceName, $appName);
        }

        if ($this->threshold === UsageAlertHistory::THRESHOLD_CRITICAL) {
            return $this->criticalEmail($message, $featureName, $workspaceName, $percentage, $remaining, $appName);
        }

        return $this->warningEmail($message, $featureName, $workspaceName, $percentage, $remaining, $appName);
    }

    /**
     * Get email subject based on threshold.
     */
    protected function getSubject(string $featureName, int $percentage): string
    {
        if ($this->threshold === UsageAlertHistory::THRESHOLD_LIMIT) {
            return "{$featureName} limit reached";
        }

        return "{$featureName} usage at {$percentage}%";
    }

    /**
     * Build warning email (80% threshold).
     */
    protected function warningEmail(
        MailMessage $message,
        string $featureName,
        string $workspaceName,
        int $percentage,
        int $remaining,
        string $appName
    ): MailMessage {
        return $message
            ->greeting('Hi,')
            ->line("Your **{$workspaceName}** workspace is approaching its **{$featureName}** limit.")
            ->line("**Current usage:** {$this->used} of {$this->limit} ({$percentage}%)")
            ->line("**Remaining:** {$remaining}")
            ->line('Consider upgrading your plan to ensure uninterrupted service.')
            ->action('View Usage', route('hub.billing'))
            ->line('If you have questions about your plan, please contact our support team.')
            ->salutation("Cheers, the {$appName} team");
    }

    /**
     * Build critical email (90% threshold).
     */
    protected function criticalEmail(
        MailMessage $message,
        string $featureName,
        string $workspaceName,
        int $percentage,
        int $remaining,
        string $appName
    ): MailMessage {
        return $message
            ->greeting('Hi,')
            ->line("**Urgent:** Your **{$workspaceName}** workspace is almost at its **{$featureName}** limit.")
            ->line("**Current usage:** {$this->used} of {$this->limit} ({$percentage}%)")
            ->line("**Only {$remaining} remaining**")
            ->line('Upgrade now to avoid any service interruptions.')
            ->action('Upgrade Plan', route('hub.billing'))
            ->line('Need help? Contact our support team.')
            ->salutation("Cheers, the {$appName} team");
    }

    /**
     * Build limit reached email (100% threshold).
     */
    protected function limitReachedEmail(
        MailMessage $message,
        string $featureName,
        string $workspaceName,
        string $appName
    ): MailMessage {
        return $message
            ->greeting('Hi,')
            ->line("Your **{$workspaceName}** workspace has reached its **{$featureName}** limit.")
            ->line("**Usage:** {$this->used} of {$this->limit} (100%)")
            ->line('You will not be able to use this feature until:')
            ->line('- You upgrade to a higher plan, or')
            ->line('- Your usage resets (if applicable), or')
            ->line('- You reduce your current usage')
            ->action('Upgrade Plan', route('hub.billing'))
            ->line('Need assistance? Our support team is here to help.')
            ->salutation("Cheers, the {$appName} team");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'usage_alert',
            'workspace_id' => $this->workspace->id,
            'workspace_name' => $this->workspace->name,
            'feature_code' => $this->feature->code,
            'feature_name' => $this->feature->name,
            'threshold' => $this->threshold,
            'used' => $this->used,
            'limit' => $this->limit,
            'percentage' => round(($this->used / $this->limit) * 100),
        ];
    }
}

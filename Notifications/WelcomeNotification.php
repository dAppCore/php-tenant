<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return (new MailMessage)
            ->subject('Welcome to Host UK')
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line('Thanks for creating your Host UK account. You\'re all set to start building your online presence.')
            ->line('Here\'s what you can do next:')
            ->line('• **BioHost** – Create a bio page with 60+ content blocks')
            ->line('• **SocialHost** – Schedule posts across 20+ social platforms')
            ->line('• **AnalyticsHost** – Track your website visitors with privacy-first analytics')
            ->line('• **TrustHost** – Add social proof widgets to your site')
            ->line('• **NotifyHost** – Send browser push notifications')
            ->action('Go to Dashboard', route('hub.dashboard'))
            ->line('If you have any questions, just reply to this email.')
            ->salutation('Cheers, the Host UK team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'welcome',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Notifications;

use Core\Mod\Tenant\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected WaitlistEntry $entry
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
        $registerUrl = route('register', ['invite' => $this->entry->invite_code]);
        $name = $this->entry->name ?: 'there';

        return (new MailMessage)
            ->subject('Your Host UK invite is ready')
            ->greeting("Hello {$name},")
            ->line('Good news. Your spot on the Host UK waitlist has come up and you can now create your account.')
            ->line('**Your invite code:** '.$this->entry->invite_code)
            ->line('As an early member, you\'ll get **50% off your first 3 months** when you upgrade to a paid plan.')
            ->action('Create your account', $registerUrl)
            ->line('This invite is linked to your email address and can only be used once.')
            ->line('Here\'s what you\'ll get access to:')
            ->line('• **BioHost** – Create bio pages with 60+ content blocks')
            ->line('• **SocialHost** – Schedule posts across 20+ social platforms')
            ->line('• **AnalyticsHost** – Privacy-first website analytics')
            ->line('• **TrustHost** – Social proof widgets for your site')
            ->line('• **NotifyHost** – Browser push notifications')
            ->line('Questions? Just reply to this email.')
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
            'type' => 'waitlist_invite',
            'invite_code' => $this->entry->invite_code,
        ];
    }
}

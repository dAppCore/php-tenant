<?php

declare(strict_types=1);

namespace Core\Tenant\Listeners;

use Core\Tenant\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendWelcomeEmail implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        // Send welcome email after registration (queued)
        $event->user->notify(new WelcomeNotification);
    }
}

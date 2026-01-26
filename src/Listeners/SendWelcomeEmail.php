<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Listeners;

use Core\Mod\Tenant\Notifications\WelcomeNotification;
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

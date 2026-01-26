<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Notifications;

use Core\Mod\Tenant\Models\Boost;
use Core\Mod\Tenant\Models\Feature;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Notification sent when boosts expire at billing cycle end.
 */
class BoostExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Workspace $workspace,
        protected Collection $expiredBoosts
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
        $workspaceName = $this->workspace->name;
        $appName = config('core.app.name', 'Host UK');
        $boostCount = $this->expiredBoosts->count();

        $message = (new MailMessage)
            ->subject($this->getSubject($boostCount, $workspaceName))
            ->greeting('Hi,');

        if ($boostCount === 1) {
            $boost = $this->expiredBoosts->first();
            $featureName = $this->getFeatureName($boost->feature_code);

            return $message
                ->line("A boost for **{$featureName}** has expired in your **{$workspaceName}** workspace.")
                ->line('This was a cycle-bound boost that ended with your billing period.')
                ->line($this->getBoostDescription($boost))
                ->action('View Usage', route('hub.billing'))
                ->line('You can purchase additional boosts or upgrade your plan to restore this capacity.')
                ->salutation("Cheers, the {$appName} team");
        }

        // Multiple boosts expired
        $message->line("The following boosts have expired in your **{$workspaceName}** workspace:");

        foreach ($this->expiredBoosts as $boost) {
            $featureName = $this->getFeatureName($boost->feature_code);
            $message->line("- **{$featureName}**: {$this->getBoostDescription($boost)}");
        }

        return $message
            ->line('These were cycle-bound boosts that ended with your billing period.')
            ->action('View Usage', route('hub.billing'))
            ->line('You can purchase additional boosts or upgrade your plan to restore this capacity.')
            ->salutation("Cheers, the {$appName} team");
    }

    /**
     * Get email subject.
     */
    protected function getSubject(int $boostCount, string $workspaceName): string
    {
        if ($boostCount === 1) {
            $boost = $this->expiredBoosts->first();
            $featureName = $this->getFeatureName($boost->feature_code);

            return "{$featureName} boost expired - {$workspaceName}";
        }

        return "{$boostCount} boosts expired - {$workspaceName}";
    }

    /**
     * Get the feature name for a code.
     */
    protected function getFeatureName(string $featureCode): string
    {
        $feature = Feature::where('code', $featureCode)->first();

        return $feature?->name ?? ucwords(str_replace(['.', '_', '-'], ' ', $featureCode));
    }

    /**
     * Get description of what the boost provided.
     */
    protected function getBoostDescription(Boost $boost): string
    {
        if ($boost->boost_type === Boost::BOOST_TYPE_UNLIMITED) {
            return 'Unlimited access';
        }

        if ($boost->boost_type === Boost::BOOST_TYPE_ENABLE) {
            return 'Feature access';
        }

        $consumed = $boost->consumed_quantity ?? 0;
        $total = $boost->limit_value ?? 0;

        return "+{$total} capacity ({$consumed} used)";
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'boost_expired',
            'workspace_id' => $this->workspace->id,
            'workspace_name' => $this->workspace->name,
            'boosts' => $this->expiredBoosts->map(fn ($boost) => [
                'id' => $boost->id,
                'feature_code' => $boost->feature_code,
                'boost_type' => $boost->boost_type,
                'limit_value' => $boost->limit_value,
                'consumed_quantity' => $boost->consumed_quantity,
            ])->toArray(),
            'count' => $this->expiredBoosts->count(),
        ];
    }
}

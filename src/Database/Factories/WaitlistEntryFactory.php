<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Database\Factories;

use Core\Mod\Tenant\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Core\Mod\Tenant\Models\WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->optional(0.8)->name(),
            'source' => fake()->randomElement(['direct', 'twitter', 'linkedin', 'google', 'referral']),
            'interest' => fake()->optional(0.5)->randomElement(['SocialHost', 'BioHost', 'AnalyticsHost', 'TrustHost', 'NotifyHost']),
            'invite_code' => null,
            'invited_at' => null,
            'registered_at' => null,
            'user_id' => null,
            'notes' => null,
            'bonus_code' => null,
        ];
    }

    /**
     * Indicate the entry has been invited.
     */
    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'invite_code' => strtoupper(fake()->bothify('????????')),
            'invited_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'bonus_code' => 'LAUNCH50',
        ]);
    }

    /**
     * Indicate the entry has converted to a user.
     */
    public function converted(): static
    {
        return $this->invited()->state(fn (array $attributes) => [
            'registered_at' => fake()->dateTimeBetween($attributes['invited_at'] ?? '-7 days', 'now'),
        ]);
    }
}

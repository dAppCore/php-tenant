<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Database\Factories;

use Core\Mod\Tenant\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Core\Mod\Tenant\Models\WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    protected $model = WorkspaceInvitation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token' => Str::random(64),
            'role' => 'member',
            'invited_by' => null,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    /**
     * Indicate the invitation has been accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ]);
    }

    /**
     * Indicate the invitation has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => fake()->dateTimeBetween('-30 days', '-1 day'),
            'accepted_at' => null,
        ]);
    }

    /**
     * Set the role to admin.
     */
    public function asAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * Set the role to owner.
     */
    public function asOwner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'owner',
        ]);
    }
}

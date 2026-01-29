<?php

declare(strict_types=1);

namespace Core\Tenant\Database\Factories;

use Core\Tenant\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Core\Tenant\Models\WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    protected $model = WorkspaceInvitation::class;

    /**
     * The plaintext token for the last created invitation.
     *
     * Since tokens are hashed, tests may need access to the original plaintext.
     */
    public static ?string $lastPlaintextToken = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Store the plaintext token so tests can access it if needed
        static::$lastPlaintextToken = Str::random(64);

        return [
            'email' => fake()->unique()->safeEmail(),
            // Token will be hashed by the model's creating event
            'token' => static::$lastPlaintextToken,
            'role' => 'member',
            'invited_by' => null,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    /**
     * Create a factory with a specific plaintext token.
     *
     * Useful for tests that need to know the token before creation.
     */
    public function withPlaintextToken(string $token): static
    {
        static::$lastPlaintextToken = $token;

        return $this->state(fn (array $attributes) => [
            'token' => $token,
        ]);
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

<?php

declare(strict_types=1);

namespace Core\Tenant\Database\Factories;

use Core\Tenant\Enums\UserTier;
use Core\Tenant\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * Uses the backward-compatible alias class to ensure type compatibility
     * with existing code that expects Mod\Tenant\Models\User.
     */
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // The users table has no `account_type` column — the real
            // column (and the one User::$fillable/casts expects) is `tier`.
            'tier' => UserTier::APOLLO,
        ];
    }

    /**
     * Create a Hades (admin) user.
     */
    public function hades(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => UserTier::HADES,
        ]);
    }

    /**
     * Create an Apollo (standard) user.
     */
    public function apollo(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => UserTier::APOLLO,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

<?php

namespace Core\Mod\Tenant\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Core\Mod\Tenant\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * Uses the backward-compatible alias class to ensure type compatibility
     * with existing code that expects Mod\Tenant\Models\User.
     */
    protected $model = \Core\Mod\Tenant\Models\User::class;

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
            'account_type' => 'apollo',
        ];
    }

    /**
     * Create a Hades (admin) user.
     */
    public function hades(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'hades',
        ]);
    }

    /**
     * Create an Apollo (standard) user.
     */
    public function apollo(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'apollo',
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

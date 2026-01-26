<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Database\Factories;

use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\UserToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for generating UserToken test instances.
 *
 * @extends Factory<UserToken>
 */
class UserTokenFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<UserToken>
     */
    protected $model = UserToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plainToken = Str::random(40);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true).' Token',
            'token' => hash('sha256', $plainToken),
            'last_used_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Indicate that the token has been used recently.
     */
    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_used_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
        ]);
    }

    /**
     * Indicate that the token expires in the future.
     *
     * @param  int  $days  Number of days until expiration
     */
    public function expiresIn(int $days = 30): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays($days),
        ]);
    }

    /**
     * Indicate that the token has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDays(1),
        ]);
    }

    /**
     * Create a token with a known plain-text value for testing.
     *
     * @param  string  $plainToken  The plain-text token value
     */
    public function withToken(string $plainToken): static
    {
        return $this->state(fn (array $attributes) => [
            'token' => hash('sha256', $plainToken),
        ]);
    }
}

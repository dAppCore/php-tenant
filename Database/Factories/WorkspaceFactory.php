<?php

namespace Core\Mod\Tenant\Database\Factories;

use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Core\Mod\Tenant\Models\Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();
        $slug = fake()->unique()->slug(2);

        return [
            'name' => $name,
            'slug' => $slug,
            'domain' => $slug.'.host.uk.com',
            'icon' => fake()->randomElement(['globe', 'building', 'newspaper', 'megaphone']),
            'color' => fake()->randomElement(['violet', 'blue', 'green', 'amber', 'rose']),
            'description' => fake()->sentence(),
            'type' => 'cms',
            'settings' => [],
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Create a CMS workspace.
     */
    public function cms(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'cms',
        ]);
    }

    /**
     * Create a static workspace.
     */
    public function static(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'static',
        ]);
    }

    /**
     * Create an inactive workspace.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create the main workspace (used in tests).
     */
    public function main(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Host UK',
            'slug' => 'main',
            'domain' => 'hestia.host.uk.com',
            'type' => 'cms',
        ]);
    }
}

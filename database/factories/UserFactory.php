<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // Ghanaian mobile format: 0 followed by a non-zero-or-one digit and 8 more.
            // Matches the frontend validator `^(\+233|0)[2-9]\d{8}$`.
            'phone' => '0'.fake()->numberBetween(2, 5).fake()->unique()->numerify('########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    /** A customer: no password, phone-verified, and explicitly the customer role. */
    public function customer(): static
    {
        return $this->state(fn () => [
            'password' => null,
            'phone_verified_at' => now(),
        ])->afterCreating(fn (User $user) => $user->assignRole(RoleEnum::Customer->value));
    }

    public function admin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(RoleEnum::Admin->value));
    }

    public function techAdmin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(RoleEnum::TechAdmin->value));
    }

    public function withRole(RoleEnum $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }
}

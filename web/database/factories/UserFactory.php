<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // The model's `hashed` cast hashes this once; a plain string keeps factories fast and explicit.
            'password' => 'S3cure!Passw0rd',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }

    public function platformAdmin(string $role = 'admin'): static
    {
        return $this->state(['platform_role' => $role]);
    }

    /** A user whose second factor is set up and confirmed. Recovery codes are recovery-code-1 and -2. */
    public function withTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}

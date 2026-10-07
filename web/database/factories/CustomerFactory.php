<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Needs an active workspace (App\Tenancy\CurrentTenant) when created, like every tenant-owned model.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+9665'.fake()->numerify('########'),
            'phone_secondary' => null,
            'email' => fake()->boolean(60) ? fake()->unique()->safeEmail() : null,
            'national_id' => fake()->numerify('##########'),
            'address' => fake()->boolean(50) ? fake()->address() : null,
            'notes' => null,
        ];
    }
}

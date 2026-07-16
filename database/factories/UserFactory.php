<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
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
        $tenant = Tenant::query()->first() ?? Tenant::query()->create([
            'uid' => (string) Str::ulid(),
            'code' => 'DEMO001',
            'name' => 'Demo Tenant',
            'is_active' => true,
        ]);

        $company = Company::query()->where('tenant_id', $tenant->id)->first()
            ?? Company::query()->create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'legal_name' => 'Demo Company Ltd',
                'display_name' => 'Demo Company',
                'email' => 'info@demo.local',
                'base_currency_code' => 'PKR',
                'default_timezone' => 'UTC',
                'is_active' => true,
            ]);

        $role = Role::query()->where('tenant_id', $tenant->id)->where('code', Role::SIGNUP_DEFAULT_ROLE)->first()
            ?? Role::query()->create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'code' => Role::SIGNUP_DEFAULT_ROLE,
                'name' => 'Owner',
                'is_system' => false,
            ]);

        return [
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= 'password',
            'is_active' => true,
        ];
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

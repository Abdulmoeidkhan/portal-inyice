<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyUserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_company_users_until_one_extra_seat_is_used(): void
    {
        $ctx = $this->seedCompanyContext('owner');
        Sanctum::actingAs($ctx['user']);

        $created = $this->postJson('/api/v1/company-users', [
            'name' => 'Sales Agent',
            'email' => 'sales-agent@test.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'sales',
        ]);

        $created->assertCreated()
            ->assertJsonPath('user.email', 'sales-agent@test.local')
            ->assertJsonPath('user.role', 'sales')
            ->assertJsonPath('limits.max', 2)
            ->assertJsonPath('limits.remaining', 0);

        $blocked = $this->postJson('/api/v1/company-users', [
            'name' => 'Extra User',
            'email' => 'extra-user@test.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'sales',
        ]);

        $blocked->assertStatus(422)
            ->assertJsonPath('limits.current', 2)
            ->assertJsonPath('limits.remaining', 0);
    }

    public function test_sales_user_cannot_manage_company_users(): void
    {
        $ctx = $this->seedCompanyContext('sales');
        Sanctum::actingAs($ctx['user']);

        $this->getJson('/api/v1/company-users')->assertStatus(403);
        $this->postJson('/api/v1/company-users', [
            'name' => 'Blocked User',
            'email' => 'blocked-user@test.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'sales',
        ])->assertStatus(403);
    }

    public function test_admin_can_update_and_delete_non_owner_company_user(): void
    {
        $ctx = $this->seedCompanyContext('admin');
        Sanctum::actingAs($ctx['user']);

        $managedUser = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $ctx['tenant']->id,
            'company_id' => $ctx['company']->id,
            'role_id' => $ctx['roles']['sales']->id,
            'name' => 'Managed User',
            'email' => 'managed-user@test.local',
            'password' => 'password123',
            'is_active' => true,
        ]);

        $this->patchJson('/api/v1/company-users/' . $managedUser->uid, [
            'name' => 'Managed Accounts',
            'email' => 'managed-accounts@test.local',
            'role' => 'accounts',
            'is_active' => false,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk()
            ->assertJsonPath('user.name', 'Managed Accounts')
            ->assertJsonPath('user.email', 'managed-accounts@test.local')
            ->assertJsonPath('user.role', 'accounts')
            ->assertJsonPath('user.is_active', false);

        $this->assertDatabaseHas('users', [
            'uid' => $managedUser->uid,
            'email' => 'managed-accounts@test.local',
            'is_active' => false,
        ]);

        $this->deleteJson('/api/v1/company-users/' . $managedUser->uid)
            ->assertOk()
            ->assertJsonPath('limits.remaining', 1);

        $this->assertDatabaseMissing('users', ['uid' => $managedUser->uid]);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCompanyContext(string $userRole): array
    {
        $tenant = Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'users' . fake()->unique()->numerify('###'),
            'name' => 'Company Users Tenant',
            'is_active' => true,
        ]);

        $company = Company::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'legal_name' => 'Company Users Legal',
            'display_name' => 'Company Users',
            'email' => 'company-users-' . fake()->unique()->safeEmail(),
            'phone' => '+92-300-5555555',
            'address' => 'Karachi, Pakistan',
            'base_currency_code' => 'PKR',
            'default_timezone' => 'Asia/Karachi',
            'is_active' => true,
        ]);

        $roles = [];

        foreach (Role::TENANT_DEFAULT_ROLES as $roleDefaults) {
            $roles[$roleDefaults['code']] = Role::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'code' => $roleDefaults['code'],
                'name' => $roleDefaults['name'],
                'is_system' => false,
            ]);
        }

        $user = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $roles[$userRole]->id,
            'name' => 'Company User Manager',
            'email' => 'manager-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        return [
            'tenant' => $tenant,
            'company' => $company,
            'roles' => $roles,
            'user' => $user,
        ];
    }
}

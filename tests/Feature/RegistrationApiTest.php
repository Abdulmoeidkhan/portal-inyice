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

class RegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_currencies_for_registration(): void
    {
        $response = $this->getJson('/api/v1/registration/currencies');

        $response->assertOk();
        $response->assertJsonFragment(['code' => 'PKR']);
        $response->assertJsonFragment(['code' => 'USD']);
    }

    public function test_it_checks_agency_code_availability(): void
    {
        Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'DEMO001',
            'name' => 'Demo Agency',
            'is_active' => true,
        ]);

        $taken = $this->getJson('/api/v1/registration/check-code?code=demo001');
        $taken->assertOk()->assertJson([
            'available' => false,
            'code' => 'DEMO001',
        ]);

        $available = $this->getJson('/api/v1/registration/check-code?code=newcode');
        $available->assertOk()->assertJson([
            'available' => true,
            'code' => 'NEWCODE',
        ]);
    }

    public function test_it_registers_new_agency_with_admin_and_default_cash_account(): void
    {
        $payload = [
            'agency_code' => 'herd001',
            'agency_name' => 'HERD Travel',
            'company_legal_name' => 'HERD Travel Private Limited',
            'company_email' => 'info@herd.test',
            'company_phone' => '+92-300-0000000',
            'billing_address' => 'Karachi, Pakistan',
            'base_currency_code' => 'PKR',
            'timezone' => 'Asia/Karachi',
            'admin_name' => 'Agency Owner',
            'admin_email' => 'owner@herd.test',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/registration/register', $payload);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('user.email', 'owner@herd.test');

        $this->assertDatabaseHas('tenants', [
            'code' => 'HERD001',
            'name' => 'HERD Travel',
        ]);

        $tenant = Tenant::where('code', 'HERD001')->firstOrFail();

        $this->assertDatabaseHas('companies', [
            'tenant_id' => $tenant->id,
            'legal_name' => 'HERD Travel Private Limited',
            'display_name' => 'HERD Travel',
            'base_currency_code' => 'PKR',
            'default_timezone' => 'Asia/Karachi',
        ]);

        $this->assertDatabaseHas('roles', [
            'tenant_id' => $tenant->id,
            'code' => 'admin',
            'name' => 'Admin',
        ]);

        $company = Company::where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertDatabaseHas('users', [
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'email' => 'owner@herd.test',
            'name' => 'Agency Owner',
        ]);

        $this->assertDatabaseHas('cash_accounts', [
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'account_name' => 'Main Cash Box',
            'currency_code' => 'PKR',
        ]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_registration_validates_required_payload(): void
    {
        $response = $this->postJson('/api/v1/registration/register', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'agency_code',
            'agency_name',
            'company_legal_name',
            'company_email',
            'company_phone',
            'billing_address',
            'base_currency_code',
            'timezone',
            'admin_name',
            'admin_email',
            'admin_password',
        ]);
    }

    public function test_protected_api_requires_authentication_and_allows_authenticated_user(): void
    {
        $unauthenticated = $this->getJson('/api/v1/user');
        $unauthenticated->assertStatus(401);

        $tenant = Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'auth001',
            'name' => 'Auth Tenant',
            'is_active' => true,
        ]);

        $company = Company::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'legal_name' => 'Auth Company',
            'display_name' => 'Auth Company',
            'email' => 'auth-company@test.local',
            'phone' => '+92-300-0000001',
            'address' => 'Lahore, Pakistan',
            'base_currency_code' => 'PKR',
            'default_timezone' => 'Asia/Karachi',
            'is_active' => true,
        ]);

        $role = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'code' => 'admin',
            'name' => 'Admin',
            'is_system' => false,
        ]);

        $user = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => 'Auth User',
            'email' => 'auth-user@test.local',
            'password' => 'password123',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $authenticated = $this->getJson('/api/v1/user');
        $authenticated->assertOk()->assertJson([
            'id' => $user->id,
            'email' => 'auth-user@test.local',
        ]);
    }
}

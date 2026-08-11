<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_company_profile_and_upload_brand_assets(): void
    {
        Storage::fake('public');
        $ctx = $this->seedCompanyContext('owner');
        Sanctum::actingAs($ctx['user']);

        $response = $this->post('/api/v1/company-profile', [
            'legal_name' => 'Updated Legal Travel',
            'display_name' => 'Updated Travel',
            'email' => 'updated@test.local',
            'phone' => '+92-300-9999999',
            'address' => 'Updated address',
            'country_code' => 'pk',
            'default_timezone' => 'Asia/Karachi',
            'logo' => UploadedFile::fake()->image('logo.png', 240, 120),
            'footer_logo' => UploadedFile::fake()->image('qr.png', 180, 180),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('company.display_name', 'Updated Travel')
            ->assertJsonPath('company.country_code', 'PK');

        $company = $ctx['company']->fresh();
        $this->assertNotNull($company->logo_path);
        $this->assertNotNull($company->footer_logo_path);
        Storage::disk('public')->assertExists($company->logo_path);
        Storage::disk('public')->assertExists($company->footer_logo_path);
    }

    public function test_non_owner_cannot_update_company_profile(): void
    {
        $ctx = $this->seedCompanyContext('admin');
        Sanctum::actingAs($ctx['user']);

        $this->postJson('/api/v1/company-profile', [
            'legal_name' => 'Blocked',
            'display_name' => 'Blocked',
            'default_timezone' => 'UTC',
        ])->assertStatus(403);
    }

    public function test_agency_owner_can_authorize_sales_cost_access(): void
    {
        $ctx = $this->seedCompanyContext('owner');
        Sanctum::actingAs($ctx['user']);

        $this->postJson('/api/v1/company-profile', [
            'legal_name' => 'Profile Company Legal',
            'display_name' => 'Profile Company',
            'email' => 'profile-company@test.local',
            'phone' => '+92-300-5555555',
            'address' => 'Karachi, Pakistan',
            'country_code' => 'PK',
            'default_timezone' => 'UTC',
            'sales_can_edit_cost' => true,
        ])->assertOk()
            ->assertJsonPath('company.sales_can_edit_cost', true);

        $this->assertTrue($ctx['company']->fresh()->sales_can_edit_cost);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCompanyContext(string $roleCode): array
    {
        $tenant = Tenant::create([
            'uid' => (string) Str::ulid(),
            'code' => 'profile' . fake()->unique()->numerify('###'),
            'name' => 'Profile Tenant',
            'is_active' => true,
        ]);

        $company = Company::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'legal_name' => 'Profile Company Legal',
            'display_name' => 'Profile Company',
            'email' => 'profile-company@test.local',
            'phone' => '+92-300-5555555',
            'address' => 'Karachi, Pakistan',
            'base_currency_code' => 'PKR',
            'default_timezone' => 'UTC',
            'monthly_invoice_limit' => 50,
            'user_limit' => 2,
            'is_active' => true,
        ]);

        $role = Role::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'code' => $roleCode,
            'name' => ucfirst($roleCode),
            'is_system' => false,
        ]);

        $user = User::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'name' => 'Profile User',
            'email' => 'profile-user-' . fake()->unique()->safeEmail(),
            'password' => 'password123',
            'is_active' => true,
        ]);

        return [
            'tenant' => $tenant,
            'company' => $company,
            'role' => $role,
            'user' => $user,
        ];
    }
}

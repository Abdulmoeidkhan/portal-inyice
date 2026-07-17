<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = Company::query()
            ->with('tenant:id,name,code')
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($request->user()->company_id);

        return response()->json([
            'company' => $this->serializeCompany($company),
            'can_update' => $request->user()->hasRole('owner'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = Company::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($request->user()->company_id);

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:200'],
            'display_name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'default_timezone' => ['required', 'timezone'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'footer_logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $company->fill([
            'legal_name' => $validated['legal_name'],
            'display_name' => $validated['display_name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'country_code' => isset($validated['country_code']) ? strtoupper($validated['country_code']) : null,
            'default_timezone' => $validated['default_timezone'],
        ]);

        if ($request->hasFile('logo')) {
            $company->logo_path = $this->replaceFile($request, 'logo', $company->logo_path, (int) $company->id);
        }

        if ($request->hasFile('footer_logo')) {
            $company->footer_logo_path = $this->replaceFile($request, 'footer_logo', $company->footer_logo_path, (int) $company->id);
        }

        $company->save();

        return response()->json([
            'success' => true,
            'company' => $this->serializeCompany($company->fresh('tenant:id,name,code')),
        ]);
    }

    private function replaceFile(Request $request, string $field, ?string $oldPath, int $companyId): string
    {
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $request->file($field)->store("company-assets/{$companyId}", 'public');
    }

    private function serializeCompany(Company $company): array
    {
        return [
            'id' => $company->id,
            'uid' => $company->uid,
            'tenant' => $company->tenant?->only(['id', 'name', 'code']),
            'legal_name' => $company->legal_name,
            'display_name' => $company->display_name,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'country_code' => $company->country_code,
            'base_currency_code' => $company->base_currency_code,
            'default_timezone' => $company->default_timezone,
            'monthly_invoice_limit' => $company->monthly_invoice_limit,
            'user_limit' => $company->user_limit,
            'logo_url' => $company->logo_url,
            'footer_logo_url' => $company->footer_logo_url,
            'is_active' => (bool) $company->is_active,
        ];
    }
}

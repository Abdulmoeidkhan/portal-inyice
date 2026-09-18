<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\InvoiceTaxService;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyProfileController extends Controller
{
    private const ALLOWED_IMAGE_EXTENSIONS = 'jpg,jpeg,png,webp';
    private const ALLOWED_IMAGE_MIME_TYPES = 'image/jpeg,image/png,image/webp';

    public function show(Request $request): JsonResponse
    {
        $company = Company::query()
            ->with('tenant:id,name,code')
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($request->user()->company_id);

        return response()->json([
            'company' => $this->serializeCompany($company),
            'tax_categories' => InvoiceTaxService::CATEGORIES,
            'can_update' => $request->user()->hasRole('owner'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = Company::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($request->user()->company_id);

        // Multipart uploads carry the nested settings as JSON, including an empty category list.
        if (is_string($request->input('tax_settings'))) {
            $request->merge(['tax_settings' => json_decode($request->input('tax_settings'), true)]);
        }

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:200'],
            'display_name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'default_timezone' => ['required', 'timezone'],
            'sales_can_edit_cost' => ['sometimes', 'boolean'],
            'tax_settings' => ['sometimes', 'required', 'array:enabled,name,registration_number,rate,pricing,categories'],
            'tax_settings.enabled' => ['required_with:tax_settings', 'boolean'],
            'tax_settings.name' => ['required_with:tax_settings', 'string', 'max:30'],
            'tax_settings.registration_number' => ['nullable', 'string', 'max:100'],
            'tax_settings.rate' => ['required_with:tax_settings', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'tax_settings.pricing' => ['required_with:tax_settings', Rule::in(['inclusive', 'exclusive'])],
            'tax_settings.categories' => ['present_with:tax_settings', 'array', 'max:6'],
            'tax_settings.categories.*' => ['string', 'distinct', Rule::in(array_keys(InvoiceTaxService::CATEGORIES))],
            'logo' => $this->imageUploadRules(),
            'footer_logo' => $this->imageUploadRules(),
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

        if (array_key_exists('sales_can_edit_cost', $validated)) {
            $company->sales_can_edit_cost = (bool) $validated['sales_can_edit_cost'];
        }

        if (array_key_exists('tax_settings', $validated)) {
            $settings = $validated['tax_settings'];
            if ($settings['enabled'] && empty($settings['categories'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'tax_settings.categories' => 'Choose at least one taxable service category.',
                ]);
            }
            $company->tax_settings = [
                ...InvoiceTaxService::defaults(), ...$settings,
                'enabled' => (bool) $settings['enabled'],
                'rate' => (string) $settings['rate'],
            ];
        }

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

    /**
     * @return array<int, string>
     */
    private function imageUploadRules(): array
    {
        return [
            'nullable',
            'file',
            'image',
            'mimes:' . self::ALLOWED_IMAGE_EXTENSIONS,
            'mimetypes:' . self::ALLOWED_IMAGE_MIME_TYPES,
            'extensions:' . self::ALLOWED_IMAGE_EXTENSIONS,
            'max:2048',
        ];
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
            'order_limit' => $company->order_limit,
            'user_limit' => $company->user_limit,
            'is_paid' => (bool) $company->is_paid,
            'sales_can_edit_cost' => (bool) $company->sales_can_edit_cost,
            'tax_settings' => $company->tax_settings ?? InvoiceTaxService::defaults(),
            'logo_url' => $company->logo_url,
            'footer_logo_url' => $company->footer_logo_url,
            'is_active' => (bool) $company->is_active,
        ];
    }
}

<?php

namespace App\Services;

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\ReportController;
use App\Models\User;
use App\Services\Accounting\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AgencyReportService
{
    public const REPORTS = [
        'aging' => 'agingReport', 'party_balances' => 'partyBalances', 'revenue' => 'revenueReport',
        'performance' => 'performanceReport', 'profit' => 'profitReport', 'tax' => 'taxReport',
        'discounts' => 'discountReport', 'payments' => 'paymentReport', 'receipts' => 'receiptReport',
        'customer_summary' => 'customerSummaryReport', 'dashboard_upcoming' => 'dashboardUpcoming',
        'cancelled' => 'cancelledReport', 'customer_statement' => null, 'vendor_statement' => null,
        'all_customer_statements' => null, 'accounting_statements' => null,
    ];

    public function rules(string $report): array
    {
        abort_unless(array_key_exists($report, self::REPORTS), 404, 'Unknown report.');
        $dates = in_array($report, ['aging', 'party_balances', 'customer_summary', 'dashboard_upcoming'], true) ? [] : [
            'from_date' => 'required|date_format:Y-m-d', 'to_date' => 'required|date_format:Y-m-d|after_or_equal:from_date',
        ];

        return $dates + match ($report) {
            'customer_statement' => ['customer_id' => 'required|integer|min:1'],
            'vendor_statement' => ['vendor_id' => 'required|integer|min:1'],
            'revenue' => ['group_by' => 'sometimes|in:day,week,month,year'],
            'profit' => ['date_by' => 'sometimes|in:creation,invoice,departure,checkin,service', 'group_by' => 'sometimes|in:customer,vendor,staff', 'entity_id' => 'sometimes|integer|min:1', 'search' => 'sometimes|string|max:100'],
            'tax' => ['currency_code' => 'sometimes|string|size:3|exists:currencies,code', 'type' => 'sometimes|in:invoice,refund', 'pricing' => 'sometimes|in:inclusive,exclusive', 'search' => 'sometimes|string|max:100'],
            'discounts' => ['discount_type' => 'sometimes|in:amount,percentage', 'customer_id' => 'sometimes|integer|min:1', 'search' => 'sometimes|string|max:100'],
            'payments', 'receipts' => ['counterparty_type' => 'sometimes|in:customer,vendor', 'counterparty_id' => 'sometimes|integer|min:1', 'payment_method' => 'sometimes|in:cash,bank_transfer,check,card', 'search' => 'sometimes|string|max:100'],
            'cancelled' => ['search' => 'sometimes|string|max:100'],
            'dashboard_upcoming' => ['days' => 'sometimes|integer|min:1|max:90'],
            default => [],
        };
    }

    public function catalog(?User $user = null): array
    {
        $reports = [];
        foreach (array_keys(self::REPORTS) as $name) {
            $roles = $name === 'cancelled' ? ['owner', 'admin', 'dev'] : ($name === 'accounting_statements' ? ['owner', 'admin', 'accounts'] : ['owner', 'admin', 'accounts', 'dev']);
            if ($user && (! in_array($user->role->code, $roles, true)
                || ($name === 'accounting_statements' && ! $user->company->hasIntegrationAccess()))) {
                continue;
            }
            $reports[] = ['name' => $name, 'roles' => $roles, 'parameters' => $this->rules($name), 'path' => '/api/v1/data-reports/'.$name];
        }

        return $reports;
    }

    public function read(User $user, string $report, array $input): array
    {
        app(AgencyDataService::class)->authorize($user, $report === 'accounting_statements' ? 'accounting_books' : null);
        if ($report === 'cancelled') {
            abort_unless($user->hasAnyRole(['owner', 'admin', 'dev']), 403);
        }
        $rules = $this->rules($report);
        if (array_diff(array_keys($input), array_keys($rules))) {
            throw ValidationException::withMessages(['arguments' => 'Only this report\'s documented parameters are allowed.']);
        }
        $data = Validator::make($input, $rules)->validate();
        if (isset($data['from_date'], $data['to_date']) && CarbonImmutable::parse($data['from_date'])->diffInDays(CarbonImmutable::parse($data['to_date'])) > 366) {
            throw ValidationException::withMessages(['to_date' => 'Select a period of at most 366 days.']);
        }
        $request = Request::create('/', 'GET', $data);
        $request->setUserResolver(fn () => $user);
        if (self::REPORTS[$report]) {
            return app(ReportController::class)->{self::REPORTS[$report]}($request)->getData(true);
        }
        if ($report === 'accounting_statements') {
            return app(AccountingController::class)->reports($request, app(Reports::class))->getData(true);
        }
        $service = app(StatementService::class);

        return $report === 'vendor_statement'
            ? $service->vendorStatement($user->tenant_id, $user->company_id, $data['vendor_id'], $data['from_date'], $data['to_date'])
            : $service->customerStatement($user->tenant_id, $user->company_id, $data['customer_id'] ?? null, $data['from_date'], $data['to_date']);
    }
}

<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\AgencyReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetAgencyReport extends AgencyReadTool
{
    protected string $name = 'get_agency_report';

    protected string $description = 'Read portal reports: tax, revenue, profit, aging, party balances, performance, discounts, payments, receipts, customer summary, upcoming travel, cancelled (owner/admin), customer/vendor statements, or separate manual accounting statements. Call list_agency_resources for each report\'s allowed parameters. Preserve currency-specific totals; date periods are at most 366 days.';

    public function schema(JsonSchema $schema): array
    {
        $properties = ['report' => $schema->string()->enum(array_keys(AgencyReportService::REPORTS))->required()];
        foreach (['from_date', 'to_date', 'group_by', 'date_by', 'search', 'currency_code', 'type', 'pricing', 'discount_type', 'counterparty_type', 'payment_method'] as $field) {
            $properties[$field] = $schema->string();
        }
        foreach (['entity_id', 'customer_id', 'vendor_id', 'counterparty_id', 'days'] as $field) {
            $properties[$field] = $schema->integer()->min(1);
        }

        return $properties;
    }

    protected function rules(): array
    {
        $rules = ['report' => 'required|string'];
        foreach (array_keys(AgencyReportService::REPORTS) as $report) {
            foreach (app(AgencyReportService::class)->rules($report) as $key => $rule) {
                $rules[$key] = 'sometimes';
            }
        }
        // Report-specific validation runs before any data access in the service.
        $rules['from_date'] = 'sometimes|date_format:Y-m-d';
        $rules['to_date'] = 'sometimes|date_format:Y-m-d';

        return $rules;
    }

    protected function read(User $user, array $data): array
    {
        $report = $data['report'];
        unset($data['report']);

        return app(AgencyReportService::class)->read($user, $report, $data);
    }
}

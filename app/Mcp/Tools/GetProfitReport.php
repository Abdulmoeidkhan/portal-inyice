<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\ReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetProfitReport extends AgencyReadTool
{
    protected string $name = 'get_profit_report';

    protected string $description = 'Read the portal profit report grouped by customer, filtered by invoice date (refunds without invoices use creation date). Returns the accounting service totals and detail for an inclusive date range.';

    public function schema(JsonSchema $schema): array
    {
        return $this->dateSchema($schema);
    }

    protected function rules(): array
    {
        return $this->dateRules();
    }

    protected function read(User $user, array $data): array
    {
        return app(ReportService::class)->profitReport($user->tenant_id, $user->company_id, $data['from_date'], $data['to_date']);
    }
}

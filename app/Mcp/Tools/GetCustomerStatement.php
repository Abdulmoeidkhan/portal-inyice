<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\StatementService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetCustomerStatement extends AgencyReadTool
{
    protected string $name = 'get_customer_statement';

    protected string $description = 'Read a customer statement for an inclusive date range using the same accounting service as the portal. First obtain customer_id using search_customers.';

    public function schema(JsonSchema $schema): array
    {
        return ['customer_id' => $schema->integer()->min(1)->required()] + $this->dateSchema($schema);
    }

    protected function rules(): array
    {
        return ['customer_id' => 'required|integer|min:1'] + $this->dateRules();
    }

    protected function read(User $user, array $data): array
    {
        return app(StatementService::class)->customerStatement($user->tenant_id, $user->company_id, $data['customer_id'], $data['from_date'], $data['to_date']);
    }
}

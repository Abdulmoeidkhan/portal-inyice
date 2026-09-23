<?php

namespace App\Mcp\Tools;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListOutstandingInvoices extends AgencyReadTool
{
    protected string $name = 'list_outstanding_invoices';

    protected string $description = 'List invoices with a positive outstanding amount, excluding void and cancelled invoices. Amounts retain their invoice currency. Optional customer filter; paginated, ordered by due date.';

    public function schema(JsonSchema $schema): array
    {
        return ['customer_id' => $schema->integer()->min(1)->description('ID returned by search_customers.')] + $this->pageSchema($schema);
    }

    protected function rules(): array
    {
        return ['customer_id' => 'sometimes|integer|min:1'] + $this->pageRules();
    }

    protected function read(User $user, array $data): array
    {
        if (isset($data['customer_id'])) {
            Customer::where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id)->findOrFail($data['customer_id']);
        }

        $query = Invoice::where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id)
            ->whereNotIn('status', ['void', 'cancel'])->where('outstanding_amount', '>', 0)
            ->whereHas('order')->when(isset($data['customer_id']), fn ($q) => $q->where('customer_id', $data['customer_id']))
            ->select(['id', 'uid', 'customer_id', 'invoice_number', 'invoice_date', 'due_date', 'currency_code', 'total_amount', 'outstanding_amount', 'status'])
            ->orderBy('due_date')->orderBy('id');

        return $this->pageResult($query, $data);
    }
}

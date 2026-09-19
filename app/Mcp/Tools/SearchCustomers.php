<?php

namespace App\Mcp\Tools;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchCustomers extends AgencyReadTool
{
    protected string $name = 'search_customers';

    protected string $description = 'Find agency customers by name, email or phone. Returns IDs for statements and invoice filters. Omit search to browse customers.';

    public function schema(JsonSchema $schema): array
    {
        return ['search' => $schema->string()->max(100)->description('Name, email or phone fragment.')] + $this->pageSchema($schema);
    }

    protected function rules(): array
    {
        return ['search' => 'sometimes|string|max:100'] + $this->pageRules();
    }

    protected function read(User $user, array $data): array
    {
        $query = Customer::where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id)
            ->select(['id', 'uid', 'name', 'email', 'phone', 'currency_code', 'is_active'])
            ->when(trim($data['search'] ?? '') !== '', function ($query) use ($data) {
                $search = '%'.trim($data['search']).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $search)->orWhere('email', 'like', $search)->orWhere('phone', 'like', $search));
            })->orderBy('name')->orderBy('id');

        return $this->pageResult($query, $data);
    }
}

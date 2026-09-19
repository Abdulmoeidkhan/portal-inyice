<?php

namespace App\Mcp\Tools;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetAccountBalance extends AgencyReadTool
{
    protected string $name = 'get_account_balance';

    protected string $description = 'Get current ledger balance for cash or bank accounts. Omit account_id to discover accounts with their IDs and balances. Bank account numbers are not returned.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_type' => $schema->string()->enum(['cash', 'bank'])->required(),
            'account_id' => $schema->integer()->min(1)->description('Optional ID from a previous call to this tool.'),
        ] + $this->pageSchema($schema);
    }

    protected function rules(): array
    {
        return ['account_type' => 'required|in:cash,bank', 'account_id' => 'sometimes|integer|min:1'] + $this->pageRules();
    }

    protected function read(User $user, array $data): array
    {
        $type = $data['account_type'];
        $model = $type === 'cash' ? CashAccount::class : BankAccount::class;
        $query = $model::where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id)->orderBy('id');
        $ledger = app(LedgerService::class);
        $transform = fn ($account) => [
            'account_id' => $account->id, 'account_name' => $type === 'cash' ? $account->account_name : $account->bank_name,
            'account_type' => $type, 'currency_code' => $account->currency_code,
            'is_active' => $account->is_active, 'opening_balance' => $account->opening_balance,
            'current_balance' => $type === 'cash' ? $ledger->getCashBalance($account->id) : $ledger->getBankBalance($account->id),
        ];

        return isset($data['account_id'])
            ? ['data' => $transform($query->findOrFail($data['account_id']))]
            : $this->pageResult($query, $data, $transform);
    }
}

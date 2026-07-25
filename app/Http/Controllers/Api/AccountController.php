<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\BankAccount;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(private LedgerService $ledgerService)
    {
    }

    /**
     * List cash accounts
     */
    public function cashAccounts(Request $request): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $accounts = CashAccount::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'uid' => $account->uid,
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'currency_code' => $account->currency_code,
                    'opening_balance' => $account->opening_balance,
                    'current_balance' => $this->ledgerService->getCashBalance($account->id),
                ];
            });

        return response()->json($accounts);
    }

    /**
     * Create cash account
     */
    public function createCashAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('cash_accounts')->where(fn ($query) => $query->where('company_id', auth()->user()->company_id)),
            ],
            'account_name' => 'required|string|max:200',
            'currency_code' => 'required|exists:currencies,code',
            'opening_balance' => 'nullable|numeric|min:0',
        ]);

        $account = CashAccount::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => auth()->user()->tenant_id,
            'company_id' => auth()->user()->company_id,
            'account_code' => $validated['account_code'],
            'account_name' => $validated['account_name'],
            'currency_code' => $validated['currency_code'],
            'opening_balance' => (float)$validated['opening_balance'] ?? 0,
            'current_balance' => (float)$validated['opening_balance'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'account' => $account,
        ], 201);
    }

    /**
     * List bank accounts
     */
    public function bankAccounts(Request $request): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $accounts = BankAccount::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'uid' => $account->uid,
                    'bank_name' => $account->bank_name,
                    'account_number' => $account->account_number,
                    'account_holder' => $account->account_holder,
                    'currency_code' => $account->currency_code,
                    'opening_balance' => $account->opening_balance,
                    'current_balance' => $this->ledgerService->getBankBalance($account->id),
                ];
            });

        return response()->json($accounts);
    }

    /**
     * Create bank account
     */
    public function createBankAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:200',
            'account_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('bank_accounts')->where(fn ($query) => $query->where('company_id', auth()->user()->company_id)),
            ],
            'account_holder' => 'required|string|max:200',
            'currency_code' => 'required|exists:currencies,code',
            'opening_balance' => 'nullable|numeric|min:0',
        ]);

        $account = BankAccount::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => auth()->user()->tenant_id,
            'company_id' => auth()->user()->company_id,
            'bank_name' => $validated['bank_name'],
            'account_number' => $validated['account_number'],
            'account_holder' => $validated['account_holder'],
            'currency_code' => $validated['currency_code'],
            'opening_balance' => (float)$validated['opening_balance'] ?? 0,
            'current_balance' => (float)$validated['opening_balance'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'account' => $account,
        ], 201);
    }

    /**
     * Get account balance
     */
    public function balance(string $accountType, int $accountId): JsonResponse
    {
        if ($accountType === 'cash') {
            $account = CashAccount::where('tenant_id', auth()->user()->tenant_id)
                ->where('company_id', auth()->user()->company_id)
                ->findOrFail($accountId);

            $balance = $this->ledgerService->getCashBalance($accountId);
        } else {
            $account = BankAccount::where('tenant_id', auth()->user()->tenant_id)
                ->where('company_id', auth()->user()->company_id)
                ->findOrFail($accountId);

            $balance = $this->ledgerService->getBankBalance($accountId);
        }

        return response()->json([
            'account_id' => $account->id,
            'account_uid' => $account->uid,
            'account_type' => $accountType,
            'currency_code' => $account->currency_code,
            'opening_balance' => $account->opening_balance,
            'current_balance' => $balance,
        ]);
    }

    /**
     * Get ledger entries for account
     */
    public function ledgerEntries(string $accountType, int $accountId, Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 100)));
        $model = $accountType === 'cash' ? CashAccount::class : BankAccount::class;
        $model::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->findOrFail($accountId);

        $entries = \App\Models\LedgerEntry::where('tenant_id', auth()->user()->tenant_id)
            ->where('account_id', $accountId)
            ->where('account_type', $accountType)
            ->orderByDesc('entry_date')
            ->paginate($perPage);

        return response()->json($entries);
    }
}

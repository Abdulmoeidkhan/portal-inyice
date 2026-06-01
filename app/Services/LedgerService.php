<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\BankAccount;
use App\Models\LedgerEntry;
use Illuminate\Support\Str;

class LedgerService
{
    /**
     * Record cash deposit to ledger
     */
    public function recordCashDeposit(
        int $tenantId,
        int $accountId,
        float $amount,
        string $description = '',
        ?int $referenceId = null
    ): LedgerEntry {
        return LedgerEntry::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'account_type' => 'cash',
            'debit' => $amount,
            'entry_date' => now()->toDateString(),
            'reference_type' => 'receipt',
            'reference_id' => $referenceId,
            'description' => $description,
            'created_at' => now(),
        ]);
    }

    /**
     * Record cash withdrawal from ledger
     */
    public function recordCashWithdrawal(
        int $tenantId,
        int $accountId,
        float $amount,
        string $description = '',
        ?int $referenceId = null
    ): LedgerEntry {
        return LedgerEntry::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'account_type' => 'cash',
            'credit' => $amount,
            'entry_date' => now()->toDateString(),
            'reference_type' => 'payment',
            'reference_id' => $referenceId,
            'description' => $description,
            'created_at' => now(),
        ]);
    }

    /**
     * Record bank deposit
     */
    public function recordBankDeposit(
        int $tenantId,
        int $accountId,
        float $amount,
        string $description = '',
        ?int $referenceId = null
    ): LedgerEntry {
        return LedgerEntry::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'account_type' => 'bank',
            'debit' => $amount,
            'entry_date' => now()->toDateString(),
            'reference_type' => 'receipt',
            'reference_id' => $referenceId,
            'description' => $description,
            'created_at' => now(),
        ]);
    }

    /**
     * Record bank withdrawal
     */
    public function recordBankWithdrawal(
        int $tenantId,
        int $accountId,
        float $amount,
        string $description = '',
        ?int $referenceId = null
    ): LedgerEntry {
        return LedgerEntry::create([
            'uid' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'account_type' => 'bank',
            'credit' => $amount,
            'entry_date' => now()->toDateString(),
            'reference_type' => 'payment',
            'reference_id' => $referenceId,
            'description' => $description,
            'created_at' => now(),
        ]);
    }

    /**
     * Get cash account balance
     */
    public function getCashBalance(int $accountId): float
    {
        $account = CashAccount::find($accountId);
        if (!$account) {
            return 0;
        }

        $debits = LedgerEntry::where('account_id', $accountId)
            ->where('account_type', 'cash')
            ->sum('debit');

        $credits = LedgerEntry::where('account_id', $accountId)
            ->where('account_type', 'cash')
            ->sum('credit');

        return (float) $account->opening_balance + $debits - $credits;
    }

    /**
     * Get bank account balance
     */
    public function getBankBalance(int $accountId): float
    {
        $account = BankAccount::find($accountId);
        if (!$account) {
            return 0;
        }

        $debits = LedgerEntry::where('account_id', $accountId)
            ->where('account_type', 'bank')
            ->sum('debit');

        $credits = LedgerEntry::where('account_id', $accountId)
            ->where('account_type', 'bank')
            ->sum('credit');

        return (float) $account->opening_balance + $debits - $credits;
    }
}

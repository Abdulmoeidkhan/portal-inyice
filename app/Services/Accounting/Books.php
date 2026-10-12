<?php

namespace App\Services\Accounting;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Books
{
    public const ACCOUNTS = [
        ['1000', 'Cash', 'asset'], ['1010', 'Bank', 'asset'],
        ['1100', 'Trade receivables', 'asset'], ['1200', 'Supplier advances', 'asset'],
        ['1300', 'Prepayments', 'asset'], ['1500', 'Equipment', 'asset'],
        ['2000', 'Trade payables', 'liability'], ['2100', 'Customer deposits', 'liability'],
        ['2200', 'Tax payable', 'liability'], ['2300', 'Accrued expenses', 'liability'],
        ['3000', 'Owner capital', 'equity'], ['3100', 'Retained earnings', 'equity'],
        ['3200', 'Owner drawings', 'equity'],
        ['4000', 'Travel sales', 'income'], ['4100', 'Commission income', 'income'],
        ['4200', 'Other income', 'income'], ['5000', 'Direct travel costs', 'expense'],
        ['6000', 'Rent', 'expense'], ['6100', 'Salaries', 'expense'],
        ['6200', 'Utilities', 'expense'], ['6300', 'Marketing', 'expense'],
        ['6400', 'Subscriptions', 'expense'], ['6500', 'Bank fees', 'expense'],
        ['6600', 'Other operating expenses', 'expense'],
    ];

    public function query(User $user): Builder
    {
        abort_unless($user->tenant_id && $user->company_id && ! $user->isSystemUser(), 403);

        return DB::table('accounting_books')->where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id);
    }

    public function get(User $user, bool $lock = false): object
    {
        $query = $this->query($user);
        $book = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $book) {
            throw ValidationException::withMessages(['accounting' => 'The company owner must set up the accounting ledger first.']);
        }

        return $book;
    }

    public function setup(User $user, string $startDate): object
    {
        return DB::transaction(function () use ($user, $startDate) {
            $company = DB::table('companies')->where('tenant_id', $user->tenant_id)->where('id', $user->company_id)->lockForUpdate()->first();
            abort_unless($company && ! $user->isSystemUser(), 403);
            if ($book = $this->query($user)->first()) {
                abort_if($book->start_date !== $startDate, 409, 'Accounting has already been set up with a different start date.');

                return $book;
            }
            $id = DB::table('accounting_books')->insertGetId([
                'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id,
                'currency_code' => $company->base_currency_code, 'start_date' => $startDate,
                'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (self::ACCOUNTS as [$code, $name, $type]) {
                DB::table('accounting_accounts')->insert([
                    'uid' => (string) Str::ulid(), 'book_id' => $id, 'code' => $code, 'name' => $name,
                    'type' => $type, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $book = $this->get($user);
            $this->audit($user, $id, 'book.created', 'book', null, (array) $book);

            return $book;
        }, 3);
    }

    public function assertOpen(object $book, string $date): void
    {
        if ($date < $book->start_date || ($book->locked_through && $date <= $book->locked_through)) {
            throw ValidationException::withMessages(['entry_date' => 'Choose a date on or after the accounting start date and after the locked period.']);
        }
    }

    public function audit(User $user, int $bookId, string $action, string $subject, ?array $before, ?array $after): void
    {
        DB::table('accounting_audit_events')->insert([
            'book_id' => $bookId, 'user_id' => $user->id, 'user_name' => $user->name,
            'action' => $action, 'subject' => $subject,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}

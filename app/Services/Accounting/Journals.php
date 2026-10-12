<?php

namespace App\Services\Accounting;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Journals
{
    public function __construct(private Books $books) {}

    public function detail(int $bookId, string $uid): array
    {
        $journal = DB::table('accounting_journals')->where('book_id', $bookId)->where('uid', $uid)->first();
        abort_unless($journal, 404);
        $lines = DB::table('accounting_journal_lines as l')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_id', $journal->id)->where('a.book_id', $bookId)
            ->orderBy('l.id')->get(['l.*', 'a.code', 'a.name', 'a.type'])->map(fn ($line) => [
                'account_id' => $line->account_id, 'code' => $line->code, 'name' => $line->name,
                'description' => $line->description, 'debit' => Amount::decimal((int) $line->debit_units),
                'credit' => Amount::decimal((int) $line->credit_units),
            ])->all();
        $reversal = DB::table('accounting_journals')->where('book_id', $bookId)->where('reversal_of_id', $journal->id)->value('uid');

        return (array) $journal + ['lines' => $lines, 'reversed_by_uid' => $reversal];
    }

    public function save(User $user, array $data, ?string $uid = null): array
    {
        return DB::transaction(function () use ($user, $data, $uid) {
            $book = $this->books->get($user, true);
            if ($uid === null) {
                $existing = DB::table('accounting_journals')->where('book_id', $book->id)->where('request_id', $data['request_id'])->first();
                if ($existing) {
                    // A retried submission returns its original journal, never duplicates it.
                    return $this->detail($book->id, $existing->uid);
                }
            }
            $this->books->assertOpen($book, $data['entry_date']);
            $lines = $this->validateLines($book, $data);
            $before = null;
            if ($uid !== null) {
                $before = $this->detail($book->id, $uid);
                abort_unless($before['status'] === 'draft', 409, 'Posted journals cannot be edited. Create a reversal instead.');
                $this->books->assertOpen($book, $before['entry_date']);
                abort_unless($before['version'] === $data['version'], 409, 'This draft changed. Reload it before saving.');
            }
            $values = [
                'entry_date' => $data['entry_date'], 'kind' => $data['kind'],
                'reference' => $data['reference'] ?? null, 'description' => $data['description'],
                'version' => ($before['version'] ?? 0) + 1, 'updated_at' => now(),
            ];
            if ($before) {
                $id = $before['id'];
                DB::table('accounting_journals')->where('book_id', $book->id)->where('id', $id)->update($values);
                DB::table('accounting_journal_lines')->where('journal_id', $id)->delete();
            } else {
                $uid = (string) Str::ulid();
                $id = DB::table('accounting_journals')->insertGetId($values + [
                    'uid' => $uid, 'book_id' => $book->id, 'request_id' => $data['request_id'],
                    'status' => 'draft', 'created_by_user_id' => $user->id, 'created_at' => now(),
                ]);
            }
            DB::table('accounting_journal_lines')->insert(array_map(fn ($line) => $line + ['journal_id' => $id], $lines));
            $after = $this->detail($book->id, $uid);
            $this->books->audit($user, $book->id, $before ? 'journal.updated' : 'journal.created', $uid, $before, $after);

            return $after;
        }, 3);
    }

    private function validateLines(object $book, array $data): array
    {
        if ($data['kind'] === 'opening' && $data['entry_date'] !== $book->start_date) {
            throw ValidationException::withMessages(['entry_date' => 'Opening balances must use the accounting start date.']);
        }
        $accounts = DB::table('accounting_accounts')->where('book_id', $book->id)->where('is_active', true)
            ->whereIn('id', array_column($data['lines'], 'account_id'))->get()->keyBy('id');
        $debits = 0;
        $credits = 0;
        $lines = [];
        foreach ($data['lines'] as $line) {
            $account = $accounts->get($line['account_id']);
            if (! $account) {
                throw ValidationException::withMessages(['lines' => 'Every account must be active and belong to this company’s ledger.']);
            }
            $debit = Amount::units($line['debit'] ?? '0');
            $credit = Amount::units($line['credit'] ?? '0');
            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages(['lines' => 'Each line must have a debit or credit, never both or neither.']);
            }
            if ($data['kind'] === 'opening' && in_array($account->type, ['income', 'expense'], true)) {
                throw ValidationException::withMessages(['lines' => 'Opening balances use asset, liability and equity accounts. Carry previous earnings into equity.']);
            }
            if ($data['kind'] === 'expense' && (($debit && $account->type !== 'expense') || ($credit && $account->type !== 'asset'))) {
                throw ValidationException::withMessages(['lines' => 'Expense entries debit an expense and credit an asset account. Use a general journal for accruals.']);
            }
            if ($data['kind'] === 'transfer' && $account->type !== 'asset') {
                throw ValidationException::withMessages(['lines' => 'Transfers use asset accounts only.']);
            }
            $debits += $debit;
            $credits += $credit;
            $lines[] = ['account_id' => $account->id, 'description' => $line['description'] ?? null,
                'debit_units' => $debit, 'credit_units' => $credit];
        }
        if ($debits !== $credits || $debits === 0) {
            throw ValidationException::withMessages(['lines' => 'Total debits must equal total credits exactly and be greater than zero.']);
        }
        if (count(array_unique(array_column($lines, 'account_id'))) < 2) {
            throw ValidationException::withMessages(['lines' => 'Use at least two different accounts.']);
        }

        return $lines;
    }

    public function post(User $user, string $uid, int $version): array
    {
        return DB::transaction(function () use ($user, $uid, $version) {
            $book = $this->books->get($user, true);
            $before = $this->detail($book->id, $uid);
            if ($before['status'] === 'posted') {
                return $before;
            }
            abort_unless($before['status'] === 'draft', 409, 'Only a draft journal can be posted.');
            abort_unless($before['version'] === $version, 409, 'This draft changed. Review its latest version before posting.');
            $this->books->assertOpen($book, $before['entry_date']);
            $this->validateLines($book, $before);
            if ($before['kind'] === 'opening') {
                $alreadyPosted = DB::table('accounting_journals as j')->where('j.book_id', $book->id)
                    ->where('j.kind', 'opening')->where('j.status', 'posted')->whereNull('j.reversal_of_id')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('accounting_journals as r')
                        ->whereColumn('r.reversal_of_id', 'j.id')->whereColumn('r.entry_date', 'j.entry_date'))->exists();
                abort_if($alreadyPosted, 409, 'Opening balances are already posted. Reverse the opening journal before replacing it.');
            }
            $this->markPosted($user, $book, $before['id']);
            $after = $this->detail($book->id, $uid);
            $this->books->audit($user, $book->id, 'journal.posted', $uid, $before, $after);

            return $after;
        }, 3);
    }

    private function markPosted(User $user, object $book, int $id): void
    {
        DB::table('accounting_journals')->where('book_id', $book->id)->where('id', $id)->update([
            'status' => 'posted', 'number' => 'J-'.str_pad((string) $book->next_number, 8, '0', STR_PAD_LEFT),
            'posted_by_user_id' => $user->id, 'posted_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('accounting_books')->where('id', $book->id)->increment('next_number');
    }

    public function reverse(User $user, string $uid, string $date, string $reason): array
    {
        return DB::transaction(function () use ($user, $uid, $date, $reason) {
            $book = $this->books->get($user, true);
            $original = $this->detail($book->id, $uid);
            abort_unless($original['status'] === 'posted', 409, 'Only posted journals can be reversed.');
            if ($original['reversed_by_uid']) {
                return $this->detail($book->id, $original['reversed_by_uid']);
            }
            abort_if($original['reversal_of_id'], 409, 'A reversal cannot itself be reversed. Use a new correcting journal.');
            $this->books->assertOpen($book, $date);
            if ($date < $original['entry_date']) {
                throw ValidationException::withMessages(['entry_date' => 'A reversal cannot precede its original entry.']);
            }
            $reversalUid = (string) Str::ulid();
            $id = DB::table('accounting_journals')->insertGetId([
                'uid' => $reversalUid, 'book_id' => $book->id, 'request_id' => (string) Str::uuid(),
                'entry_date' => $date, 'kind' => 'reversal', 'description' => $reason,
                'reference' => $original['number'], 'reversal_of_id' => $original['id'],
                'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($original['lines'] as $line) {
                DB::table('accounting_journal_lines')->insert([
                    'journal_id' => $id, 'account_id' => $line['account_id'], 'description' => $line['description'],
                    'debit_units' => Amount::units($line['credit']), 'credit_units' => Amount::units($line['debit']),
                ]);
            }
            $this->markPosted($user, $book, $id);
            $after = $this->detail($book->id, $reversalUid);
            $this->books->audit($user, $book->id, 'journal.reversed', $uid, $original, $after);

            return $after;
        }, 3);
    }
}

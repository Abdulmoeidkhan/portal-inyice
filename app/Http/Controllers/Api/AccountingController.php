<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Books;
use App\Services\Accounting\Journals;
use App\Services\Accounting\Reports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountingController extends Controller
{
    public function __construct(private Books $books, private Journals $journals) {}

    public function overview(Request $request)
    {
        if (! Schema::hasTable('accounting_books')) {
            return $this->json(['available' => false, 'book' => null, 'accounts' => [], 'message' => 'Accounting is awaiting its database migration.']);
        }
        $book = $this->books->query($request->user())->first();

        return $this->json([
            'available' => true, 'book' => $book,
            'company_currency' => $request->user()->company?->base_currency_code,
            'accounts' => $book && $request->boolean('include_accounts', true) ? DB::table('accounting_accounts')->where('book_id', $book->id)->orderBy('code')->get() : [],
            'coverage' => 'Manual accounting ledger. Existing orders, invoices, receipts, payments and their reports remain separate.',
        ]);
    }

    public function setup(Request $request)
    {
        $data = $request->validate(['start_date' => 'required|date_format:Y-m-d', 'confirm_separate_ledger' => 'required|accepted']);

        return $this->json(['book' => $this->books->setup($request->user(), $data['start_date'])], 201);
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:20|regex:/^[A-Za-z0-9-]+$/', 'name' => 'required|string|max:160',
            'type' => 'required|in:asset,liability,equity,income,expense',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $book = $this->books->get($request->user(), true);
            $data['code'] = strtoupper($data['code']);
            if (DB::table('accounting_accounts')->where('book_id', $book->id)->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'This account code is already in use.']);
            }
            $id = DB::table('accounting_accounts')->insertGetId($data + [
                'uid' => (string) Str::ulid(), 'book_id' => $book->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $account = DB::table('accounting_accounts')->where('id', $id)->first();
            $this->books->audit($request->user(), $book->id, 'account.created', $account->uid, null, (array) $account);

            return $this->json(['account' => $account], 201);
        }, 3);
    }

    public function updateAccount(Request $request, string $uid)
    {
        $data = $request->validate(['name' => 'required|string|max:160', 'is_active' => 'required|boolean']);

        return DB::transaction(function () use ($request, $data, $uid) {
            $book = $this->books->get($request->user(), true);
            $query = DB::table('accounting_accounts')->where('book_id', $book->id)->where('uid', $uid);
            $before = (clone $query)->first();
            abort_unless($before, 404);
            $query->update($data + ['updated_at' => now()]);
            $after = $query->first();
            $this->books->audit($request->user(), $book->id, 'account.updated', $uid, (array) $before, (array) $after);

            return $this->json(['account' => $after]);
        }, 3);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => 'nullable|integer|min:1', 'status' => 'nullable|in:draft,posted,discarded',
            'from_date' => 'nullable|date_format:Y-m-d', 'to_date' => 'nullable|date_format:Y-m-d|after_or_equal:from_date',
        ]);
        $book = $this->books->get($request->user());
        $query = DB::table('accounting_journals')->where('book_id', $book->id);
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['from_date'])) {
            $query->where('entry_date', '>=', $data['from_date']);
        }
        if (! empty($data['to_date'])) {
            $query->where('entry_date', '<=', $data['to_date']);
        }

        return $this->json($query->orderByDesc('id')->paginate(25));
    }

    public function show(Request $request, string $uid)
    {
        return $this->json(['journal' => $this->journals->detail($this->books->get($request->user())->id, $uid)]);
    }

    public function storeJournal(Request $request)
    {
        $data = $this->journalData($request, false);

        return $this->json(['journal' => $this->journals->save($request->user(), $data)], 201);
    }

    public function updateJournal(Request $request, string $uid)
    {
        $data = $this->journalData($request, true);

        return $this->json(['journal' => $this->journals->save($request->user(), $data, $uid)]);
    }

    private function journalData(Request $request, bool $updating): array
    {
        $book = $this->books->get($request->user());

        return $request->validate([
            'request_id' => $updating ? 'sometimes|uuid' : 'required|uuid',
            'version' => $updating ? 'required|integer|min:1' : 'sometimes|integer|min:1',
            'entry_date' => 'required|date_format:Y-m-d',
            'currency_code' => ['required', Rule::in([$book->currency_code])],
            'kind' => 'required|in:general,opening,expense,transfer',
            'description' => 'required|string|max:2000', 'reference' => 'nullable|string|max:160',
            'lines' => 'required|array|min:2|max:100', 'lines.*.account_id' => 'required|integer|min:1',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.debit' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/D'],
            'lines.*.credit' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/D'],
        ]);
    }

    public function post(Request $request, string $uid)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);

        return $this->json(['journal' => $this->journals->post($request->user(), $uid, $data['version'])]);
    }

    public function reverse(Request $request, string $uid)
    {
        $data = $request->validate(['entry_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:2000']);

        return $this->json(['journal' => $this->journals->reverse($request->user(), $uid, $data['entry_date'], $data['reason'])], 201);
    }

    public function discard(Request $request, string $uid)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']);

        return DB::transaction(function () use ($request, $uid, $data) {
            $book = $this->books->get($request->user(), true);
            $before = $this->journals->detail($book->id, $uid);
            abort_unless($before['status'] === 'draft' && $before['version'] === $data['version'], 409, 'Reload the journal; only its current draft can be discarded.');
            DB::table('accounting_journals')->where('book_id', $book->id)->where('uid', $uid)->update(['status' => 'discarded', 'updated_at' => now()]);
            $after = $this->journals->detail($book->id, $uid);
            $this->books->audit($request->user(), $book->id, 'journal.discarded', $uid, $before, $after + ['reason' => $data['reason']]);

            return $this->json(['journal' => $after]);
        }, 3);
    }

    public function lockPeriod(Request $request)
    {
        $data = $request->validate(['locked_through' => 'required|date_format:Y-m-d|before_or_equal:today', 'reason' => 'required|string|max:2000']);

        return DB::transaction(function () use ($request, $data) {
            $book = $this->books->get($request->user(), true);
            $this->books->assertOpen($book, $data['locked_through']);
            if (DB::table('accounting_journals')->where('book_id', $book->id)->where('status', 'draft')->where('entry_date', '<=', $data['locked_through'])->exists()) {
                throw ValidationException::withMessages(['locked_through' => 'Post or discard all drafts in this period before closing it.']);
            }
            DB::table('accounting_books')->where('id', $book->id)->update(['locked_through' => $data['locked_through'], 'updated_at' => now()]);
            $this->books->audit($request->user(), $book->id, 'period.locked', 'book', ['locked_through' => $book->locked_through], $data);

            return $this->json(['book' => $this->books->get($request->user())]);
        }, 3);
    }

    public function reports(Request $request, Reports $reports)
    {
        $data = $request->validate(['from_date' => 'required|date_format:Y-m-d', 'to_date' => 'required|date_format:Y-m-d|after_or_equal:from_date']);
        $book = $this->books->get($request->user());
        if ($data['from_date'] < $book->start_date) {
            throw ValidationException::withMessages(['from_date' => 'Reports begin on the accounting start date.']);
        }

        return $this->json($reports->statements($book, $data['from_date'], $data['to_date']));
    }

    public function ledger(Request $request, string $uid)
    {
        $data = $request->validate([
            'from_date' => 'required|date_format:Y-m-d', 'to_date' => 'required|date_format:Y-m-d|after_or_equal:from_date',
            'page' => 'nullable|integer|min:1',
        ]);
        $book = $this->books->get($request->user());
        $account = DB::table('accounting_accounts')->where('book_id', $book->id)->where('uid', $uid)->first();
        abort_unless($account, 404);
        $base = DB::table('accounting_journal_lines as l')->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.book_id', $book->id)->where('j.status', 'posted')->where('l.account_id', $account->id);
        $opening = (clone $base)->where('j.entry_date', '<', $data['from_date'])->selectRaw('COALESCE(SUM(l.debit_units - l.credit_units), 0) as amount')->value('amount');
        $rows = $base->whereBetween('j.entry_date', [$data['from_date'], $data['to_date']])
            ->orderBy('j.entry_date')->orderBy('j.id')->orderBy('l.id')
            ->paginate(50, ['j.uid', 'j.number', 'j.entry_date', 'j.description', 'j.reference', 'l.id as line_id', 'l.debit_units', 'l.credit_units']);
        $rows->through(fn ($row) => [
            'uid' => $row->uid, 'number' => $row->number, 'entry_date' => $row->entry_date,
            'description' => $row->description, 'reference' => $row->reference, 'line_id' => $row->line_id,
            'debit' => \App\Services\Accounting\Amount::decimal((int) $row->debit_units),
            'credit' => \App\Services\Accounting\Amount::decimal((int) $row->credit_units),
        ]);

        return $this->json(['account' => $account, 'opening_debit_minus_credit' => \App\Services\Accounting\Amount::decimal((int) $opening), 'entries' => $rows]);
    }

    public function audit(Request $request)
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $book = $this->books->get($request->user());
        $rows = DB::table('accounting_audit_events')->where('book_id', $book->id)->orderByDesc('id')->paginate(25);
        $rows->through(function ($row) {
            $row->before = $row->before ? json_decode($row->before, true) : null;
            $row->after = $row->after ? json_decode($row->after, true) : null;

            return $row;
        });

        return $this->json($rows);
    }

    private function json(mixed $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}

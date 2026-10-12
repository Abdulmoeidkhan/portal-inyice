<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\Amount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private array $accounts = [];

    public function test_setup_is_opt_in_idempotent_and_uses_the_company_currency(): void
    {
        $owner = $this->user();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/accounting')->assertOk()->assertJsonPath('book', null);
        $this->assertDatabaseCount('accounting_books', 0);
        $this->postJson('/api/v1/accounting/setup', ['start_date' => '2026-01-01'])->assertUnprocessable();
        $this->setupLedger($owner);
        $this->setupLedger($owner);
        $this->assertDatabaseCount('accounting_books', 1);
        $this->assertDatabaseCount('accounting_accounts', 24);
        $this->assertDatabaseCount('accounting_audit_events', 1);
        $this->postJson('/api/v1/accounting/setup', ['start_date' => '2026-02-01', 'confirm_separate_ledger' => true])->assertConflict();
        $other = $this->user('owner', null, 'USD');
        $this->setupLedger($other);
        $this->getJson('/api/v1/accounting')->assertJsonPath('book.currency_code', 'USD');
        $other->company->update(['base_currency_code' => 'PKR']);
        $this->getJson('/api/v1/accounting')->assertJsonPath('book.currency_code', 'USD');
    }

    public function test_exact_amounts_and_balancing_reject_rounding_negative_and_overprecision(): void
    {
        $this->setupLedger($this->user());
        $data = $this->payload();
        $data['lines'] = [
            ['account_id' => $this->accounts['1000'], 'debit' => '0.1', 'credit' => '0'],
            ['account_id' => $this->accounts['1010'], 'debit' => '0.2', 'credit' => '0'],
            ['account_id' => $this->accounts['3000'], 'debit' => '0', 'credit' => '0.3'],
        ];
        $this->postJson('/api/v1/accounting/journals', $data)->assertCreated();
        foreach (['0.3001', '-1', '0.30001', '1e3', '999999999999', 'NaN'] as $invalid) {
            $data['request_id'] = (string) Str::uuid();
            $data['lines'][2]['credit'] = $invalid;
            $this->postJson('/api/v1/accounting/journals', $data)->assertUnprocessable();
        }
        $this->assertDatabaseCount('accounting_journals', 1);
        $this->assertSame('0.3000', Amount::decimal(Amount::units('0.1') + Amount::units('0.2')));
    }

    public function test_posted_reports_include_openings_expenses_income_and_separate_reversals(): void
    {
        $this->setupLedger($this->user());
        $opening = $this->createAndPost($this->payload('opening', '1000', '3000', '1000'));
        $income = $this->createAndPost($this->payload('general', '1000', '4000', '200'));
        $expense = $this->createAndPost($this->payload('expense', '6000', '1000', '50'));
        $this->postJson('/api/v1/accounting/journals', $this->payload('expense', '6000', '1000', '10'))->assertCreated();
        $this->getJson('/api/v1/accounting/reports?from_date=2026-01-01&to_date=2026-01-31')
            ->assertOk()->assertJsonPath('profit_loss.net_profit', '150.0000')
            ->assertJsonPath('balance_sheet.assets', '1150.0000')->assertJsonPath('balance_sheet.equity', '1000.0000')
            ->assertJsonPath('balance_sheet.balanced', true)->assertJsonPath('trial_balance.balanced', true);
        $reverse = $this->postJson('/api/v1/accounting/journals/'.$expense['uid'].'/reverse', ['entry_date' => '2026-02-01', 'reason' => 'Expense entered twice'])
            ->assertCreated()->json('journal');
        $this->postJson('/api/v1/accounting/journals/'.$expense['uid'].'/reverse', ['entry_date' => '2026-02-01', 'reason' => 'Retry'])
            ->assertCreated()->assertJsonPath('journal.uid', $reverse['uid']);
        $this->getJson('/api/v1/accounting/reports?from_date=2026-01-01&to_date=2026-01-31')->assertJsonPath('profit_loss.net_profit', '150.0000');
        $this->getJson('/api/v1/accounting/reports?from_date=2026-02-01&to_date=2026-02-28')
            ->assertJsonPath('profit_loss.net_profit', '50.0000')->assertJsonPath('balance_sheet.unclosed_earnings', '200.0000')
            ->assertJsonPath('balance_sheet.assets', '1200.0000');
        $this->assertDatabaseHas('accounting_journals', ['uid' => $expense['uid'], 'status' => 'posted']);
        $this->assertNotSame($opening['number'], $income['number']);
    }

    public function test_posting_is_idempotent_and_immutable(): void
    {
        $this->setupLedger($this->user());
        $payload = $this->payload();
        $draft = $this->postJson('/api/v1/accounting/journals', $payload)->assertCreated()->json('journal');
        $this->postJson('/api/v1/accounting/journals', $payload)->assertCreated()->assertJsonPath('journal.uid', $draft['uid']);
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/post', ['version' => 1])->assertOk();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/post', ['version' => 1])->assertOk()->assertJsonPath('journal.number', 'J-00000001');
        $this->patchJson('/api/v1/accounting/journals/'.$draft['uid'], $payload + ['version' => 1])->assertConflict();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/discard', ['version' => 1, 'reason' => 'No'])->assertConflict();
        $this->assertDatabaseCount('accounting_journals', 1);
        $this->assertDatabaseCount('accounting_journal_lines', 2);
    }

    public function test_stale_draft_cannot_be_overwritten_or_posted_and_discard_is_terminal(): void
    {
        $this->setupLedger($this->user());
        $payload = $this->payload();
        $draft = $this->postJson('/api/v1/accounting/journals', $payload)->json('journal');
        $this->patchJson('/api/v1/accounting/journals/'.$draft['uid'], $payload + ['version' => 1])->assertOk()->assertJsonPath('journal.version', 2);
        $this->patchJson('/api/v1/accounting/journals/'.$draft['uid'], $payload + ['version' => 1])->assertConflict();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/post', ['version' => 1])->assertConflict();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/discard', ['version' => 2, 'reason' => 'Duplicate'])->assertOk();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/post', ['version' => 2])->assertConflict();
    }

    public function test_period_lock_checks_drafts_and_disallows_backdating_but_allows_current_reversals(): void
    {
        $this->setupLedger($this->user());
        $payload = $this->payload();
        $draft = $this->postJson('/api/v1/accounting/journals', $payload)->json('journal');
        $this->postJson('/api/v1/accounting/period-lock', ['locked_through' => '2026-01-31', 'reason' => 'Close January'])->assertUnprocessable();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/post', ['version' => 1])->assertOk();
        $this->postJson('/api/v1/accounting/period-lock', ['locked_through' => '2026-01-31', 'reason' => 'Close January'])->assertOk();
        $payload['request_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/accounting/journals', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/accounting/period-lock', ['locked_through' => '2026-01-15', 'reason' => 'Reopen'])->assertUnprocessable();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/reverse', ['entry_date' => '2026-01-20', 'reason' => 'Correction'])->assertUnprocessable();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/reverse', ['entry_date' => '2026-02-01', 'reason' => 'Correction'])->assertCreated();
    }

    public function test_role_permissions_separate_preparation_posting_and_owner_setup(): void
    {
        $owner = $this->user();
        $this->setupLedger($owner);
        $accounts = $this->user('accounts', $owner->company);
        Sanctum::actingAs($accounts);
        $this->postJson('/api/v1/accounting/setup', ['start_date' => '2026-01-01', 'confirm_separate_ledger' => true])->assertForbidden();
        $draft = $this->postJson('/api/v1/accounting/journals', $this->payload())->assertCreated()->json('journal');
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/post', ['version' => 1])->assertForbidden();
        $this->postJson('/api/v1/accounting/period-lock', ['locked_through' => '2026-01-31', 'reason' => 'Close'])->assertForbidden();
        Sanctum::actingAs($this->user('admin', $owner->company));
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/post', ['version' => 1])->assertOk();
        Sanctum::actingAs($this->user('dev', $owner->company));
        $this->getJson('/api/v1/accounting')->assertForbidden();
        $this->getJson('/api/v1/accounting/audit')->assertForbidden();
        $this->postJson('/api/v1/accounting/journals', $this->payload())->assertForbidden();
        Sanctum::actingAs($this->user('sales', $owner->company));
        $this->getJson('/api/v1/accounting')->assertForbidden();
    }

    public function test_company_isolation_for_reads_writes_accounts_and_reports(): void
    {
        $one = $this->user();
        $this->setupLedger($one);
        $firstAccounts = $this->accounts;
        $draft = $this->postJson('/api/v1/accounting/journals', $this->payload())->json('journal');
        $oneAccount = DB::table('accounting_accounts')->where('id', $firstAccounts['1000'])->first();
        $two = $this->user('owner', null, 'PKR', $one->tenant_id);
        $this->setupLedger($two);
        $this->getJson('/api/v1/accounting/journals/'.$draft['uid'])->assertNotFound();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/post', ['version' => 1])->assertNotFound();
        $payload = $this->payload();
        $payload['lines'][0]['account_id'] = $firstAccounts['1000'];
        $this->postJson('/api/v1/accounting/journals', $payload)->assertUnprocessable();
        $this->patchJson('/api/v1/accounting/accounts/'.$oneAccount->uid, ['name' => 'Stolen', 'is_active' => false])->assertNotFound();
        $this->getJson('/api/v1/accounting/accounts/'.$oneAccount->uid.'/ledger?from_date=2026-01-01&to_date=2026-01-31')->assertNotFound();
        $this->getJson('/api/v1/accounting/journals')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/accounting/reports?from_date=2026-01-01&to_date=2026-01-31')->assertJsonPath('balance_sheet.assets', '0.0000');
    }

    public function test_opening_rules_currency_and_deactivated_accounts_are_enforced(): void
    {
        $this->setupLedger($this->user());
        $bad = $this->payload('opening', '1000', '4000');
        $this->postJson('/api/v1/accounting/journals', $bad)->assertUnprocessable();
        $bad = $this->payload('opening');
        $bad['entry_date'] = '2026-01-02';
        $this->postJson('/api/v1/accounting/journals', $bad)->assertUnprocessable();
        $bad = $this->payload(); $bad['currency_code'] = 'USD';
        $this->postJson('/api/v1/accounting/journals', $bad)->assertUnprocessable();
        $bad = $this->payload(); $bad['entry_date'] = '2025-12-31';
        $this->postJson('/api/v1/accounting/journals', $bad)->assertUnprocessable();
        $original = $this->createAndPost($this->payload('opening'));
        $second = $this->postJson('/api/v1/accounting/journals', $this->payload('opening'))->json('journal');
        $this->postJson('/api/v1/accounting/journals/'.$second['uid'].'/post', ['version' => 1])->assertConflict();
        $this->postJson('/api/v1/accounting/journals/'.$original['uid'].'/reverse', ['entry_date' => '2026-01-01', 'reason' => 'Correct opening'])->assertCreated();
        $this->postJson('/api/v1/accounting/journals/'.$second['uid'].'/post', ['version' => 1])->assertOk();
        $draft = $this->postJson('/api/v1/accounting/journals', $this->payload())->json('journal');
        $cash = DB::table('accounting_accounts')->where('id', $this->accounts['1000'])->first();
        $this->patchJson('/api/v1/accounting/accounts/'.$cash->uid, ['name' => 'Cash', 'is_active' => false])->assertOk();
        $this->postJson('/api/v1/accounting/journals/'.$draft['uid'].'/post', ['version' => 1])->assertUnprocessable();
    }

    public function test_accounting_operations_leave_legacy_financial_tables_unchanged(): void
    {
        $owner = $this->user();
        $tables = ['orders', 'invoices', 'payments', 'receipts', 'cash_accounts', 'bank_accounts', 'ledger_entries'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->setupLedger($owner);
        $this->createAndPost($this->payload('opening'));
        $this->createAndPost($this->payload('expense', '6000', '1000', '5'));
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table.' was modified');
        }
        $this->getJson('/api/v1/accounting/audit')->assertOk()->assertJsonPath('total', 5);
        $this->assertDatabaseCount('accounting_journal_lines', 4);
    }

    public function test_account_ledger_opening_excludes_drafts_and_filters_by_date(): void
    {
        $this->setupLedger($this->user());
        $this->createAndPost($this->payload('opening'));
        $next = $this->payload('general', '1000', '4000', '20'); $next['entry_date'] = '2026-02-01';
        $this->createAndPost($next);
        $cash = DB::table('accounting_accounts')->where('id', $this->accounts['1000'])->value('uid');
        $this->getJson('/api/v1/accounting/accounts/'.$cash.'/ledger?from_date=2026-02-01&to_date=2026-02-28')
            ->assertOk()->assertJsonPath('opening_debit_minus_credit', '100.0000')->assertJsonPath('entries.total', 1)
            ->assertJsonPath('entries.data.0.debit', '20.0000');
    }

    public function test_overview_can_skip_accounts_for_independent_pages(): void
    {
        $this->setupLedger($this->user());

        $this->getJson('/api/v1/accounting?include_accounts=0')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('accounts', [])
            ->assertJsonPath('book.start_date', '2026-01-01');

        $this->assertNotEmpty($this->getJson('/api/v1/accounting')->assertOk()->json('accounts'));
    }

    public function test_accounting_requires_a_paid_plan_for_reads_and_writes(): void
    {
        foreach (['owner', 'admin', 'accounts', 'sales', 'dev'] as $role) {
            $user = $this->user($role);
            $user->company->update(['is_paid' => false]);
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/accounting')->assertForbidden();
            $this->getJson('/api/v1/accounting/audit')->assertForbidden();
            $this->postJson('/api/v1/accounting/setup', [])->assertForbidden();
            $this->postJson('/api/v1/accounting/journals', [])->assertForbidden();
        }
    }

    private function payload(string $kind = 'general', string $debit = '1000', string $credit = '3000', string $amount = '100'): array
    {
        return [
            'request_id' => (string) Str::uuid(), 'entry_date' => '2026-01-01', 'currency_code' => 'PKR',
            'kind' => $kind, 'description' => 'Reviewed accounting entry', 'reference' => 'TEST',
            'lines' => [
                ['account_id' => $this->accounts[$debit], 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $this->accounts[$credit], 'debit' => '0', 'credit' => $amount],
            ],
        ];
    }

    private function createAndPost(array $payload): array
    {
        $journal = $this->postJson('/api/v1/accounting/journals', $payload)->assertCreated()->json('journal');

        return $this->postJson('/api/v1/accounting/journals/'.$journal['uid'].'/post', ['version' => 1])->assertOk()->json('journal');
    }

    private function setupLedger(User $user): void
    {
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/accounting/setup', ['start_date' => '2026-01-01', 'confirm_separate_ledger' => true])->assertCreated();
        $overview = $this->getJson('/api/v1/accounting')->assertOk()->json();
        $this->accounts = collect($overview['accounts'])->pluck('id', 'code')->all();
    }

    private function user(string $role = 'owner', ?Company $company = null, string $currency = 'PKR', ?int $tenantId = null): User
    {
        Notification::fake();
        if (! $company) {
            $tenantId ??= Tenant::create(['uid' => (string) Str::ulid(), 'code' => Str::random(12), 'name' => 'Accounting test', 'is_active' => true])->id;
            $company = Company::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'legal_name' => 'Accounting test', 'display_name' => 'Accounting test',
                'base_currency_code' => $currency, 'default_timezone' => 'Asia/Karachi', 'is_active' => true, 'is_paid' => true]);
        }
        $roleModel = Role::firstOrCreate(['tenant_id' => $company->tenant_id, 'code' => $role], ['uid' => (string) Str::ulid(), 'name' => $role, 'is_system' => false]);

        return User::create(['uid' => (string) Str::ulid(), 'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'role_id' => $roleModel->id,
            'name' => 'Accountant', 'email' => Str::ulid().'@example.com', 'password' => 'password123', 'email_verified_at' => now(), 'is_active' => true]);
    }
}

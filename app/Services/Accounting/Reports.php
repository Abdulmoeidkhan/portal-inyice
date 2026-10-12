<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

class Reports
{
    public function statements(object $book, string $from, string $to): array
    {
        $movements = DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.book_id', $book->id)->where('j.status', 'posted')->where('j.entry_date', '<=', $to)
            ->selectRaw('l.account_id, SUM(l.debit_units) as debit, SUM(l.credit_units) as credit')
            ->selectRaw('SUM(CASE WHEN j.entry_date < ? THEN l.debit_units - l.credit_units ELSE 0 END) as opening', [$from])
            ->selectRaw('SUM(CASE WHEN j.entry_date >= ? THEN l.debit_units ELSE 0 END) as period_debit', [$from])
            ->selectRaw('SUM(CASE WHEN j.entry_date >= ? THEN l.credit_units ELSE 0 END) as period_credit', [$from])
            ->groupBy('l.account_id')->get()->keyBy('account_id');
        $totals = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 0];
        $period = ['income' => 0, 'expense' => 0];
        $debits = 0;
        $credits = 0;
        $rows = [];
        foreach (DB::table('accounting_accounts')->where('book_id', $book->id)->orderBy('code')->get() as $account) {
            $m = $movements->get($account->id);
            $debit = (int) ($m->debit ?? 0);
            $credit = (int) ($m->credit ?? 0);
            $net = $debit - $credit;
            $normalDebit = in_array($account->type, ['asset', 'expense'], true);
            $totals[$account->type] += $normalDebit ? $net : -$net;
            if (isset($period[$account->type])) {
                $periodNet = (int) ($m->period_debit ?? 0) - (int) ($m->period_credit ?? 0);
                $period[$account->type] += $normalDebit ? $periodNet : -$periodNet;
            }
            $debits += max($net, 0);
            $credits += max(-$net, 0);
            $rows[] = [
                'account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type,
                'opening' => Amount::decimal((int) ($m->opening ?? 0)),
                'period_debit' => Amount::decimal((int) ($m->period_debit ?? 0)),
                'period_credit' => Amount::decimal((int) ($m->period_credit ?? 0)),
                'debit' => Amount::decimal(max($net, 0)), 'credit' => Amount::decimal(max(-$net, 0)),
                'balance' => Amount::decimal($normalDebit ? $net : -$net),
                'period_balance' => Amount::decimal(($normalDebit ? 1 : -1) * ((int) ($m->period_debit ?? 0) - (int) ($m->period_credit ?? 0))),
            ];
        }
        $earnings = $totals['income'] - $totals['expense'];

        return [
            'currency_code' => $book->currency_code, 'from_date' => $from, 'to_date' => $to,
            'coverage' => 'Only posted journals in this accounting ledger. Existing operational transactions are not automatically included.',
            'accounts' => $rows,
            'trial_balance' => ['debit' => Amount::decimal($debits), 'credit' => Amount::decimal($credits), 'balanced' => $debits === $credits],
            'profit_loss' => ['income' => Amount::decimal($period['income']), 'expenses' => Amount::decimal($period['expense']), 'net_profit' => Amount::decimal($period['income'] - $period['expense'])],
            'balance_sheet' => [
                'assets' => Amount::decimal($totals['asset']), 'liabilities' => Amount::decimal($totals['liability']),
                'equity' => Amount::decimal($totals['equity']), 'unclosed_earnings' => Amount::decimal($earnings),
                'liabilities_and_equity' => Amount::decimal($totals['liability'] + $totals['equity'] + $earnings),
                'balanced' => $totals['asset'] === $totals['liability'] + $totals['equity'] + $earnings,
            ],
        ];
    }
}

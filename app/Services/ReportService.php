<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportService
{
    /**
     * Generate invoice aging report
     */
    public function invoiceAgingReport(int $tenantId, int $companyId): array
    {
        $invoices = Invoice::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('status', ['issued', 'sent', 'partial_paid', 'overdue'])
            ->with(['customer', 'order'])
            ->get();

        $current = collect([]);
        $days30 = collect([]);
        $days60 = collect([]);
        $days90 = collect([]);
        $overdue = collect([]);

        $today = now();

        foreach ($invoices as $invoice) {
            $daysOverdue = Carbon::parse($invoice->due_date)->diffInDays($today, false);

            $record = [
                'invoice_uid' => $invoice->uid,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer->name,
                'invoice_date' => $invoice->invoice_date,
                'due_date' => $invoice->due_date,
                'amount' => $invoice->total_amount,
                'outstanding' => $invoice->outstanding_amount,
                'days_overdue' => $daysOverdue,
            ];

            if ($daysOverdue > 90) {
                $overdue->push($record);
            } elseif ($daysOverdue > 60) {
                $days90->push($record);
            } elseif ($daysOverdue > 30) {
                $days60->push($record);
            } elseif ($daysOverdue > 0) {
                $days30->push($record);
            } else {
                $current->push($record);
            }
        }

        return [
            'report_date' => now()->toDateString(),
            'company_id' => $companyId,
            'buckets' => [
                'current' => [
                    'description' => 'Not yet due',
                    'invoices' => $current->toArray(),
                    'total_outstanding' => $current->sum('outstanding'),
                    'count' => $current->count(),
                ],
                'days_1_30' => [
                    'description' => '1-30 days overdue',
                    'invoices' => $days30->toArray(),
                    'total_outstanding' => $days30->sum('outstanding'),
                    'count' => $days30->count(),
                ],
                'days_31_60' => [
                    'description' => '31-60 days overdue',
                    'invoices' => $days60->toArray(),
                    'total_outstanding' => $days60->sum('outstanding'),
                    'count' => $days60->count(),
                ],
                'days_61_90' => [
                    'description' => '61-90 days overdue',
                    'invoices' => $days90->toArray(),
                    'total_outstanding' => $days90->sum('outstanding'),
                    'count' => $days90->count(),
                ],
                'days_over_90' => [
                    'description' => 'Over 90 days overdue',
                    'invoices' => $overdue->toArray(),
                    'total_outstanding' => $overdue->sum('outstanding'),
                    'count' => $overdue->count(),
                ],
            ],
            'summary' => [
                'total_outstanding' => $invoices->sum('outstanding_amount'),
                'total_invoices' => $invoices->count(),
            ],
        ];
    }

    /**
     * Generate revenue report by period
     */
    public function revenueReport(
        int $tenantId,
        int $companyId,
        string $fromDate,
        string $toDate,
        string $groupBy = 'month'
    ): array {
        $invoices = Invoice::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('invoice_date', '>=', $fromDate)
            ->where('invoice_date', '<=', $toDate)
            ->with(['customer'])
            ->get();

        $grouped = match($groupBy) {
            'day' => $invoices->groupBy(fn($i) => Carbon::parse($i->invoice_date)->toDateString()),
            'week' => $invoices->groupBy(fn($i) => Carbon::parse($i->invoice_date)->weekOfYear),
            'month' => $invoices->groupBy(fn($i) => Carbon::parse($i->invoice_date)->format('Y-m')),
            'year' => $invoices->groupBy(fn($i) => Carbon::parse($i->invoice_date)->year),
            default => $invoices,
        };

        $report = [];
        foreach ($grouped as $period => $items) {
            $report[] = [
                'period' => $period,
                'total_revenue' => $items->sum('total_amount'),
                'total_paid' => $items->sum(fn($i) => $i->total_amount - $i->outstanding_amount),
                'total_outstanding' => $items->sum('outstanding_amount'),
                'invoice_count' => $items->count(),
                'average_invoice_value' => $items->avg('total_amount'),
            ];
        }

        return [
            'report_date' => now()->toDateString(),
            'company_id' => $companyId,
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'group_by' => $groupBy,
            'data' => $report,
            'summary' => [
                'total_revenue' => $invoices->sum('total_amount'),
                'total_collected' => $invoices->sum(fn($i) => $i->total_amount - $i->outstanding_amount),
                'total_outstanding' => $invoices->sum('outstanding_amount'),
                'total_invoices' => $invoices->count(),
            ],
        ];
    }

    /**
     * Generate customer summary report
     */
    public function customerSummaryReport(int $tenantId, int $companyId): array
    {
        $customers = \App\Models\Customer::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['invoices'])
            ->get();

        $customerData = $customers->map(function ($customer) {
            $invoices = $customer->invoices;
            return [
                'customer_uid' => $customer->uid,
                'customer_name' => $customer->name,
                'customer_type' => $customer->type,
                'email' => $customer->email,
                'total_invoiced' => $invoices->sum('total_amount'),
                'total_paid' => $invoices->sum(fn($i) => $i->total_amount - $i->outstanding_amount),
                'total_outstanding' => $invoices->sum('outstanding_amount'),
                'outstanding_percentage' => $invoices->sum('total_amount') > 0
                    ? round(($invoices->sum('outstanding_amount') / $invoices->sum('total_amount')) * 100, 2)
                    : 0,
                'invoice_count' => $invoices->count(),
                'last_invoice_date' => $invoices->max('invoice_date'),
            ];
        });

        return [
            'report_date' => now()->toDateString(),
            'company_id' => $companyId,
            'customers' => $customerData->toArray(),
            'summary' => [
                'total_customers' => $customers->count(),
                'total_revenue' => $customerData->sum('total_invoiced'),
                'total_outstanding' => $customerData->sum('total_outstanding'),
            ],
        ];
    }
}

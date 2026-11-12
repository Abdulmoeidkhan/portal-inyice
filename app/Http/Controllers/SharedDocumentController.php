<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SharedDocumentController extends Controller
{
    public function show(Request $request, string $document, string $token)
    {
        $isInvoice = $document === 'invoices';
        $record = ($isInvoice ? Invoice::query() : Order::query())
            ->where('share_token', $token)->with('company')->firstOrFail();
        $detailed = $isInvoice && $request->query('view') === 'detailed';
        $type = $isInvoice ? ($detailed ? 'Detailed Invoice' : 'Invoice')
            : ($document === 'itineraries' ? 'Travel Itinerary'
                : ($document === 'quotations' || $record->status === 'quote' ? 'Quotation' : 'Travel Voucher'));
        $company = $record->company;
        $brand = $company?->is_paid ? ($company->display_name ?: $company->legal_name) : 'InYice';
        $reference = $isInvoice ? $record->invoice_number : ($record->meta['voucher_no'] ?? $record->order_number);
        $date = $isInvoice ? $record->invoice_date?->format('Y-m-d')
            : ($record->meta['issue_date'] ?? $record->created_at?->format('Y-m-d'));
        $summary = [$type.' '.$reference, $date ? 'Date: '.$date : null];
        if ($isInvoice || $type === 'Quotation') {
            $summary[] = 'Total: '.$record->currency_code.' '.number_format((float) $record->total_amount, 2);
        } else {
            $summary[] = $record->meta['package_type'] ?? $record->order_type;
        }
        $summary[] = 'Status: '.Str::headline($record->status);
        $summary[] = 'View your document from '.$brand.'.';
        $image = $company?->is_paid ? $company->logo_url : null;
        $social = [
            'title' => $this->plain($type.' '.$reference.' | '.$brand),
            'description' => $this->plain(implode(' · ', array_filter($summary))),
            'brand' => $this->plain($brand),
            'image' => $image ? url($image) : asset('images/icons/icon-512x512.png'),
            'url' => url('/shared/'.$document.'/'.$token).($detailed ? '?view=detailed' : ''),
        ];

        return response()->view('app', compact('social'))
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function plain(string $value): string
    {
        return Str::limit(Str::squish(strip_tags($value)), 300);
    }
}

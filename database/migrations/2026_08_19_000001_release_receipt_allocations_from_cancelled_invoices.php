<?php

use App\Models\Receipt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoice_settlements')
            ->whereIn('invoice_id', DB::table('invoices')
                ->select('id')
                ->whereIn('status', ['cancel', 'void']))
            ->where('invoice_settlements.reference_document_type', Receipt::class)
            ->where('invoice_settlements.amount_received', '>', 0)
            ->where('invoice_settlements.settlement_type', 'payment')
            ->delete();
    }

    public function down(): void
    {
        //
    }
};

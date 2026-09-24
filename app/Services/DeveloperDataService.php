<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DeveloperDataService
{
    // Explicit fields ensure future credential columns cannot enter exports by accident.
    public const FIELDS = [
        'companies' => 'id,uid,legal_name,display_name,email,phone,address,country_code,base_currency_code,default_timezone,is_active,tax_settings',
        'users' => 'id,uid,role_id,name,email,is_active,created_at',
        'customers' => 'id,uid,type,name,email,phone,address,city,country_code,postal_code,tax_id,currency_code,is_active',
        'vendors' => 'id,uid,type,name,email,phone,address,city,country_code,postal_code,tax_id,currency_code,payment_terms,is_active',
        'orders' => 'id,uid,customer_id,vendor_id,created_by_user_id,updated_by_user_id,order_number,voucher_no,issue_date,package_type,active_sections,emergency_contact,booking_reference,status,currency_code,total_amount,notes,gds_source,meta,created_at,updated_at',
        'order_items' => 'id,uid,order_id,description,quantity,unit_price,total_price,gds_data',
        'order_vendor_costs' => 'id,order_id,vendor_id,service_type,service_index,amount',
        'order_internal_notes' => 'id,uid,order_id,user_id,body,created_at',
        'order_status_histories' => 'id,uid,order_id,from_status,to_status,user_id,notes,created_at',
        'invoices' => 'id,uid,order_id,customer_id,invoice_number,invoice_date,due_date,currency_code,subtotal,tax_amount,total_amount,outstanding_amount,advance_balance,status,fx_rate_to_base,notes,tax_settings,created_at,updated_at',
        'invoice_lines' => 'id,uid,invoice_id,description,quantity,unit_price,total_price,tax_category',
        'invoice_discounts' => 'id,uid,invoice_id,invoice_line_id,discount_type,percentage,amount,reason,created_by_user_id,updated_by_user_id,created_at,updated_at',
        'invoice_settlements' => 'id,uid,invoice_id,amount_received,amount_refunded,amount_to_advance,settlement_date,settlement_type,reference_document_id,reference_document_type,status,notes',
        'payments' => 'id,uid,vendor_id,customer_id,payment_number,payment_date,amount,currency_code,payment_method,account_id,account_type,reference_number,description,created_by_user_id',
        'receipts' => 'id,uid,customer_id,vendor_id,receipt_number,receipt_date,amount,currency_code,payment_method,account_id,account_type,reference_number,description,created_by_user_id',
        'receivings' => 'id,uid,receiving_number,amount,paid_by,received_by_user_id,reference_customer_id,received_at,notes,status,created_by_user_id,updated_by_user_id',
        'cash_accounts' => 'id,uid,account_code,account_name,currency_code,opening_balance,current_balance,is_active',
        'bank_accounts' => 'id,uid,bank_name,account_number,account_holder,currency_code,opening_balance,current_balance,is_active',
        'ledger_entries' => 'id,uid,account_id,account_type,debit,credit,entry_date,reference_type,reference_id,description,created_at',
        'profit_shares' => 'id,uid,from_user_id,to_user_id,invoice_id,share_date,currency_code,amount,notes',
        'refund_allocations' => 'id,uid,order_id,payment_id,receipt_id,allocation_type,amount',
        'vendor_payment_allocations' => 'id,uid,payment_id,order_id,amount',
    ];

    public function query(User $user, string $resource): Builder
    {
        abort_unless(isset(self::FIELDS[$resource]), 404);
        abort_unless($user->tenant_id && $user->company_id, 403);
        $query = DB::table($resource)->where('tenant_id', $user->tenant_id);

        if ($resource === 'companies') {
            $query->where('id', $user->company_id);
        } elseif (in_array($resource, ['order_items', 'order_vendor_costs', 'order_internal_notes', 'order_status_histories', 'refund_allocations', 'vendor_payment_allocations'], true)) {
            $query->whereIn('order_id', $this->companyIds($user, 'orders')->whereNull('deleted_at'));
        } elseif (in_array($resource, ['invoice_lines', 'invoice_settlements'], true)) {
            $query->whereIn('invoice_id', $this->companyIds($user, 'invoices'));
        } elseif ($resource === 'ledger_entries') {
            $query->where(function (Builder $accounts) use ($user): void {
                $accounts->where(fn (Builder $cash) => $cash->where('account_type', 'cash')->whereIn('account_id', $this->companyIds($user, 'cash_accounts')))
                    ->orWhere(fn (Builder $bank) => $bank->where('account_type', 'bank')->whereIn('account_id', $this->companyIds($user, 'bank_accounts')));
            });
        } else {
            $query->where('company_id', $user->company_id);
        }

        if (in_array($resource, ['orders', 'users', 'receivings'], true)) {
            $query->whereNull('deleted_at');
        }

        return $query->select(explode(',', self::FIELDS[$resource]))->orderBy('id');
    }

    private function companyIds(User $user, string $table): Builder
    {
        return DB::table($table)->select('id')->where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id);
    }
}

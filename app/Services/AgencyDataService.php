<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgencyDataService
{
    public const EXTRA_FIELDS = [
        'currencies' => 'id,code,name,symbol,decimal_places,is_active',
        'roles' => 'id,uid,code,name',
        'exchange_rates' => 'id,uid,from_currency_code,to_currency_code,rate,rate_date,source,is_active',
        'gds_parsed_records' => 'id,uid,gds_source,booking_reference,parsed_json,parsed_by_user_id,created_at',
        'accounting_books' => 'id,currency_code,start_date,locked_through,created_at',
        'accounting_accounts' => 'id,uid,book_id,code,name,type,is_active',
        'accounting_journals' => 'id,uid,book_id,number,entry_date,kind,status,reference,description,version,created_by_user_id,posted_by_user_id,posted_at,reversal_of_id,created_at',
        'accounting_journal_lines' => 'id,journal_id,account_id,description,debit_units,credit_units',
        'accounting_audit_events' => 'id,book_id,user_id,user_name,action,subject,before,after,created_at',
    ];

    public function fields(): array
    {
        $fields = DeveloperDataService::FIELDS + self::EXTRA_FIELDS;
        $fields['companies'] .= ',voucher_notes,itinerary_notes,monthly_invoice_limit,order_limit,user_limit,is_paid,sales_can_edit_cost,logo_path,footer_logo_path';
        // Keep the existing MCP bank-number privacy boundary.
        $fields['bank_accounts'] = str_replace(',account_number', '', $fields['bank_accounts']);

        return array_map(fn ($value) => explode(',', $value), $fields);
    }

    public function authorize(User $user, ?string $resource = null): void
    {
        abort_unless(! $user->isSystemUser() && $user->is_active && $user->hasVerifiedEmail()
            && $user->hasAnyRole(['owner', 'admin', 'accounts', 'dev'])
            && $user->tenant?->is_active && $user->company?->is_active
            && (int) $user->company->tenant_id === (int) $user->tenant_id, 403);
        if (in_array($resource, ['users', 'roles'], true)) {
            abort_unless($user->hasAnyRole(['owner', 'admin', 'dev']), 403);
        }
        if (str_starts_with($resource ?? '', 'accounting_')) {
            abort_unless($user->company->hasIntegrationAccess() && $user->hasAnyRole(['owner', 'admin', 'accounts']), 403);
        }
    }

    public function catalog(?User $user = null): array
    {
        if ($user) {
            $this->authorize($user);
        }
        $resources = [];
        foreach ($this->fields() as $name => $fields) {
            $roles = str_starts_with($name, 'accounting_') ? ['owner', 'admin', 'accounts']
                : (in_array($name, ['users', 'roles'], true) ? ['owner', 'admin', 'dev'] : ['owner', 'admin', 'accounts', 'dev']);
            if ($user && (! in_array($user->role->code, $roles, true)
                || (str_starts_with($name, 'accounting_') && ! $user->company->hasIntegrationAccess()))) {
                continue;
            }
            $resources[] = [
                'name' => $name, 'fields' => $fields, 'roles' => $roles,
                'path' => '/api/v1/data/'.$name,
                'scope' => $name === 'currencies' ? 'global reference data' : (in_array($name, ['roles', 'exchange_rates'], true) ? 'tenant' : 'company'),
                'notes' => $name === 'accounting_journal_lines' ? 'debit_units and credit_units are integers scaled by 10000. Manual accounting is separate from travel accounts.' : 'Nested JSON columns are returned as stored JSON strings. Only listed fields can be filtered.',
            ];
        }

        return ['resources' => $resources, 'pagination' => ['default_per_page' => 25, 'max_per_page' => 100],
            'excluded' => ['credentials', 'tokens and share tokens', 'sessions', 'provider administration', 'raw GDS input', 'tenant-wide integration/security audit records', 'deleted records', 'bank account numbers']];
    }

    public function read(User $user, string $resource, array $input): array
    {
        $this->authorize($user, $resource);
        abort_unless(isset($this->fields()[$resource]), 404, 'Unknown data resource.');
        $allowed = ['page', 'per_page', 'id', 'uid', 'search', 'filter_field', 'filter_value'];
        if (array_diff(array_keys($input), $allowed)) {
            throw ValidationException::withMessages(['arguments' => 'Only documented query parameters are allowed.']);
        }
        $fields = $this->fields()[$resource];
        $data = Validator::make($input, [
            'page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|min:1|max:100',
            'id' => 'sometimes|integer|min:1', 'uid' => 'sometimes|string|max:26',
            'search' => 'sometimes|string|max:100',
            'filter_field' => ['required_with:filter_value', 'string', Rule::in($fields)],
            'filter_value' => 'required_with:filter_field|string|max:255',
        ])->validate();
        if (isset($data['uid']) && ! in_array('uid', $fields, true)) {
            throw ValidationException::withMessages(['uid' => 'This resource uses id, not uid.']);
        }
        $query = $this->query($user, $resource)->select($fields);
        foreach (['id', 'uid'] as $key) {
            if (isset($data[$key])) {
                $query->where($key, $data[$key]);
            }
        }
        if (isset($data['filter_field'])) {
            $query->where($data['filter_field'], $data['filter_value']);
        }
        if (trim($data['search'] ?? '') !== '') {
            $searchFields = array_values(array_intersect($fields, ['name', 'email', 'phone', 'description', 'order_number', 'invoice_number', 'payment_number', 'receipt_number', 'receiving_number', 'booking_reference', 'account_name', 'bank_name', 'display_name', 'reference', 'number', 'code', 'body']));
            if (! $searchFields) {
                throw ValidationException::withMessages(['search' => 'Use id, uid or an exact field filter for this resource.']);
            }
            $query->where(function (Builder $q) use ($searchFields, $data): void {
                foreach ($searchFields as $field) {
                    $q->orWhere($field, 'like', '%'.trim($data['search']).'%');
                }
            });
        }
        $page = $query->orderBy('id')->simplePaginate($data['per_page'] ?? 25, $fields, 'page', $data['page'] ?? 1);

        return ['resource' => $resource, 'data' => $page->items(), 'page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'has_more' => $page->hasMorePages()];
    }

    private function query(User $user, string $resource): Builder
    {
        if (isset(DeveloperDataService::FIELDS[$resource])) {
            return app(DeveloperDataService::class)->query($user, $resource)->reorder();
        }
        $query = DB::table($resource);
        $books = fn () => DB::table('accounting_books')->select('id')->where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id);

        return match ($resource) {
            'currencies' => $query,
            'roles' => $query->where('tenant_id', $user->tenant_id)->where('is_system', false),
            'exchange_rates' => $query->where('tenant_id', $user->tenant_id),
            'gds_parsed_records' => $query->where('tenant_id', $user->tenant_id)->whereIn('parsed_by_user_id',
                DB::table('users')->select('id')->where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id)->whereNull('deleted_at')),
            'accounting_books' => $query->where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id),
            'accounting_journal_lines' => $query->whereIn('journal_id', DB::table('accounting_journals')->select('id')->whereIn('book_id', $books())),
            default => $query->whereIn('book_id', $books()),
        };
    }
}

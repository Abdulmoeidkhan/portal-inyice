<?php

namespace App\Services;

class ApiExampleService
{
    public function catalog(): array
    {
        $items = [];
        foreach (app(AgencyDataService::class)->catalog()['resources'] as $resource) {
            $items[] = $this->summary('data.'.$resource['name'], $resource['path'], $resource['roles']);
        }
        foreach (app(AgencyReportService::class)->catalog() as $report) {
            $items[] = $this->summary('report.'.$report['name'], $report['path'], $report['roles']);
        }

        return [
            'version' => '1.0', 'synthetic' => true,
            'description' => 'Public examples for the complete read-only agency data and report API. No customer records or live credentials are read to produce these examples. Mock URLs only return fixtures; they never execute the documented request.',
            'authentication' => [
                'api' => 'Authorization: Bearer YOUR_API_TOKEN. External API login currently supports paid, verified dev accounts with access_mode=api. Portal tokens require the matching portal cookie. Dev cannot read manual accounting; use authorized owner/admin/accounts portal sessions for those REST routes, or MCP OAuth.',
                'mcp' => 'OAuth mcp:use token from an active paid owner/admin/accounts connection. OAuth MCP tokens cannot be used on REST API routes.',
            ],
            'data_catalog' => '/api/v1/data',
            'filters' => ['id', 'uid (where present)', 'search (supported text fields)', 'filter_field + filter_value (exact match)', 'page', 'per_page (1-100)'],
            'errors' => [
                ['status' => 401, 'body' => ['message' => 'Unauthenticated.']],
                ['status' => 403, 'body' => ['message' => 'This action is unauthorized.']],
                ['status' => 404, 'body' => ['message' => 'Unknown data resource.']],
                ['status' => 422, 'body' => ['message' => 'The per page field must not be greater than 100.', 'errors' => ['per_page' => ['The per page field must not be greater than 100.']]]],
                ['status' => 429, 'body' => ['message' => 'Too Many Attempts.']],
            ],
            'examples' => $items,
        ];
    }

    private function summary(string $id, string $path, array $roles): array
    {
        return ['id' => $id, 'method' => 'GET', 'path' => $path, 'roles' => $roles,
            'example_url' => '/api/v1/api-examples/'.$id, 'mock_url' => '/api/v1/api-mocks/'.$id];
    }

    public function example(string $id): array
    {
        $entry = collect($this->catalog()['examples'])->firstWhere('id', $id);
        abort_unless($entry, 404, 'Unknown API example.');
        [$kind, $name] = explode('.', $id, 2);
        if ($kind === 'data') {
            $resource = collect(app(AgencyDataService::class)->catalog()['resources'])->firstWhere('name', $name);
            $query = ['page' => 1, 'per_page' => 25];
            $record = [];
            foreach ($resource['fields'] as $field) {
                $record[$field] = $this->sampleValue($field);
            }
            if ($name === 'orders') {
                $record['status'] = 'order';
            }
            if ($name === 'currencies') {
                $record['code'] = 'PKR';
            }
            if ($name === 'roles') {
                $record['code'] = 'admin';
            }
            if ($name === 'accounting_accounts') {
                $record['type'] = 'asset';
                $record['code'] = '1000';
            }
            if ($name === 'invoice_discounts') {
                $record['discount_type'] = 'amount';
                $record['percentage'] = null;
            }
            if ($name === 'invoice_settlements') {
                $record['status'] = 'confirmed';
                $record['settlement_type'] = 'payment';
            }
            $response = ['resource' => $name, 'data' => [$record], 'page' => 1, 'per_page' => 25, 'has_more' => false];
            $parameters = ['page' => 'integer, 1-100000', 'per_page' => 'integer, 1-100', 'id' => 'integer', 'uid' => 'string, if this resource has a uid',
                'search' => 'text fragment, max 100 characters; supported text fields only', 'filter_field' => 'one of the listed fields', 'filter_value' => 'exact value as a string'];
            $notes = $resource['notes'];
        } else {
            // Captured from isolated, synthetic test agencies, never from the application database.
            $fixtures = json_decode(file_get_contents(resource_path('api/report-examples.json')), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(isset($fixtures[$name]), 404, 'Report example unavailable.');
            $query = $fixtures[$name]['query'];
            $response = $fixtures[$name]['response'];
            $parameters = app(AgencyReportService::class)->rules($name);
            $notes = 'Success response for a synthetic agency with no travel transactions. Report periods are inclusive and limited to 366 days. Manual accounting remains a separate ledger. Preserve currency-specific totals.';
        }

        return $entry + [
            'synthetic' => true, 'notes' => $notes, 'parameters' => $parameters,
            'fields' => $kind === 'data' ? $resource['fields'] : null,
            'request' => ['method' => 'GET', 'url' => $entry['path'].($query ? '?'.http_build_query($query) : ''),
                'headers' => ['Authorization' => 'Bearer YOUR_API_TOKEN', 'Accept' => 'application/json'], 'query' => (object) $query, 'body' => null],
            'response' => ['status' => 200, 'headers' => ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store'], 'body' => $response],
            'mcp_request' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
                'name' => $kind === 'data' ? 'read_agency_data' : 'get_agency_report',
                'arguments' => [$kind === 'data' ? 'resource' : 'report' => $name] + $query,
            ]],
            'mcp_response' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => json_encode($response)]], 'structuredContent' => $response, 'isError' => false]],
        ];
    }

    private function sampleValue(string $field): mixed
    {
        if ($field === 'id' || str_ends_with($field, '_id')) {
            return 1;
        }
        if ($field === 'uid') {
            return '01J00000000000000000000001';
        }
        if (in_array($field, ['is_active', 'is_paid'], true)) {
            return 1;
        }
        if ($field === 'sales_can_edit_cost') {
            return 0;
        }
        if (in_array($field, ['tax_amount', 'amount_refunded', 'amount_to_advance', 'advance_balance', 'credit'], true)) {
            return '0.0000';
        }
        if ($field === 'fx_rate_to_base') {
            return '1.00000000';
        }
        if (str_ends_with($field, 'currency_code') || $field === 'currency_code') {
            return 'PKR';
        }
        if (str_ends_with($field, '_at')) {
            return '2026-01-15 12:00:00';
        }
        if (str_contains($field, 'date') || $field === 'locked_through') {
            return '2026-01-15';
        }
        if (preg_match('/amount|balance|price|subtotal|^debit$|^credit$|^rate$/', $field)) {
            return '100.0000';
        }
        if ($field === 'debit_units') {
            return 1000000;
        }
        if ($field === 'credit_units') {
            return 0;
        }

        return match ($field) {
            'name', 'display_name', 'legal_name', 'account_name', 'bank_name', 'user_name' => 'Example Agency',
            'email' => 'example@example.com', 'phone' => '+00000000000', 'country_code' => 'PK',
            'quantity', 'version' => 1, 'decimal_places' => 2, 'default_timezone' => 'Asia/Karachi',
            'meta', 'gds_data', 'parsed_json', 'before', 'after' => '{}',
            'active_sections' => '[]', 'description', 'body', 'notes', 'voucher_notes', 'itinerary_notes' => 'Synthetic example only',
            'status' => 'draft', 'type' => 'B2C', 'kind' => 'general', 'payment_method', 'account_type' => 'cash',
            'order_number', 'invoice_number', 'payment_number', 'receipt_number', 'number', 'reference_number' => 'EXAMPLE-001',
            default => null,
        };
    }
}

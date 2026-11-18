<?php

namespace App\Mcp\Tools;

use App\Mcp\AgencyAccess;
use App\Services\McpOrderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class CreateOrder extends Tool
{
    protected string $name = 'create_order';

    protected string $description = 'Create one basic order for an existing customer in the signed-in agency. Owner/admin only with orders:create consent. Search customers first; never guess IDs or amounts. Supply a service description and its total selling amount (one item, quantity 1). Optional passenger, booking reference, issue date and notes. Creates status order only, without invoices, payments, vendor costs or external bookings. Use a new UUID request_id per intended order and reuse exactly the same arguments on retries to prevent duplicates. Only call when the user requests creation.';

    public function shouldRegister(Request $request): bool
    {
        return AgencyAccess::canCreateOrders($request->user()) && $request->user()->tokenCan('orders:create');
    }

    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['securitySchemes'] = [['type' => 'oauth2', 'scopes' => ['mcp:use', 'orders:create']]];
        $tool['_meta']['securitySchemes'] = $tool['securitySchemes'];

        return $tool;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'request_id' => $schema->string()->description('UUID for this intended order; reuse unchanged on retries.')->required(),
            'customer_id' => $schema->integer()->min(1)->description('Existing active customer ID from search_customers.')->required(),
            'description' => $schema->string()->max(255)->description('Service description.')->required(),
            'amount' => $schema->number()->min(0)->max(999999999)->description('Total selling amount, up to four decimal places.')->required(),
            'currency_code' => $schema->string()->description('Three uppercase letters; defaults to agency base currency.'),
            'issue_date' => $schema->string()->description('YYYY-MM-DD; defaults to today in agency timezone.'),
            'booking_reference' => $schema->string()->max(100),
            'passenger_name' => $schema->string()->max(255),
            'notes' => $schema->string()->max(5000),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = AgencyAccess::authorizeOrderCreation($request->user());
        $rules = [
            'request_id' => 'required|uuid', 'customer_id' => 'required|integer|min:1',
            'description' => 'required|string|max:255', 'amount' => 'required|numeric|min:0|max:999999999|decimal:0,4',
            'currency_code' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'issue_date' => 'sometimes|date_format:Y-m-d', 'booking_reference' => 'sometimes|string|max:100',
            'passenger_name' => 'sometimes|string|max:255', 'notes' => 'sometimes|string|max:5000',
        ];
        if (array_diff(array_keys($request->all()), array_keys($rules))) {
            throw ValidationException::withMessages(['arguments' => 'Only documented arguments are allowed; agency, creator and status are set by the server.']);
        }
        $data = $request->validate($rules);
        if (trim($data['description']) === '') {
            throw ValidationException::withMessages(['description' => 'A service description is required.']);
        }
        try {
            return Response::structured(app(McpOrderService::class)->create($user, $data));
        } catch (ModelNotFoundException) {
            return Response::error('Active customer or order not found in your agency.');
        }
    }
}

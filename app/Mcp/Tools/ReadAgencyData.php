<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\AgencyDataService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ReadAgencyData extends AgencyReadTool
{
    protected string $name = 'read_agency_data';

    protected string $description = 'Retrieve scoped orders/vouchers (meta includes passengers, flights and services), all invoice statuses, lines, vendors, customers, payments, receipts, allocations, notes, company settings, currencies and manual accounting. Discover resource names and fields with list_agency_resources. Filter child rows by parent ID using filter_field/filter_value. Follow has_more. Returns stored records, not recomputed balances; use report/balance tools for totals. JSON columns are encoded strings.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(array_keys(app(AgencyDataService::class)->fields()))->required(),
            'id' => $schema->integer()->min(1), 'uid' => $schema->string()->max(26),
            'search' => $schema->string()->max(100),
            'filter_field' => $schema->string()->description('Exact-match field from the resource catalog, e.g. order_id.'),
            'filter_value' => $schema->string()->max(255)->description('Exact value as a string, e.g. "12".'),
        ] + $this->pageSchema($schema);
    }

    protected function rules(): array
    {
        return ['resource' => 'required|string', 'id' => 'sometimes|integer|min:1', 'uid' => 'sometimes|string|max:26',
            'search' => 'sometimes|string|max:100', 'filter_field' => 'sometimes|string', 'filter_value' => 'sometimes|string|max:255'] + $this->pageRules();
    }

    protected function read(User $user, array $data): array
    {
        $resource = $data['resource'];
        unset($data['resource']);

        return app(AgencyDataService::class)->read($user, $resource, $data);
    }
}

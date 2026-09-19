<?php

namespace App\Mcp\Tools;

use App\Mcp\AgencyAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class AgencyReadTool extends Tool
{
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['securitySchemes'] = [['type' => 'oauth2', 'scopes' => ['mcp:use']]];
        $tool['_meta']['securitySchemes'] = $tool['securitySchemes'];

        return $tool;
    }

    final public function handle(Request $request): Response|ResponseFactory
    {
        $user = AgencyAccess::authorize($request->user());
        $rules = $this->rules();
        if (array_diff(array_keys($request->all()), array_keys($rules))) {
            throw ValidationException::withMessages(['arguments' => 'Only the documented tool arguments are allowed.']);
        }
        $data = $request->validate($rules);
        if (isset($data['from_date'], $data['to_date'])
            && CarbonImmutable::parse($data['from_date'])->diffInDays(CarbonImmutable::parse($data['to_date'])) > 366) {
            throw ValidationException::withMessages(['to_date' => 'Select a period of at most 366 days.']);
        }

        try {
            return Response::structured($this->read($user, $data));
        } catch (ModelNotFoundException) {
            return Response::error('Record not found in your agency.');
        }
    }

    abstract protected function rules(): array;

    abstract protected function read(User $user, array $data): array;

    protected function pageSchema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->description('Page number, starting at 1.'),
            'per_page' => $schema->integer()->min(1)->max(100)->description('Results per page, default 25, maximum 100.'),
        ];
    }

    protected function pageRules(): array
    {
        return ['page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }

    protected function dateSchema(JsonSchema $schema): array
    {
        return [
            'from_date' => $schema->string()->description('Inclusive start date, YYYY-MM-DD.')->required(),
            'to_date' => $schema->string()->description('Inclusive end date, YYYY-MM-DD; at most 366 days after start.')->required(),
        ];
    }

    protected function dateRules(): array
    {
        return ['from_date' => 'required|date_format:Y-m-d', 'to_date' => 'required|date_format:Y-m-d|after_or_equal:from_date'];
    }

    protected function pageResult($query, array $data, ?callable $transform = null): array
    {
        $page = $query->simplePaginate($data['per_page'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return [
            'data' => $transform ? $page->getCollection()->map($transform)->all() : $page->items(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'has_more' => $page->hasMorePages(),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\AgencyDataService;
use App\Services\AgencyReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListAgencyResources extends AgencyReadTool
{
    protected string $name = 'list_agency_resources';

    protected string $description = 'Discover available agency data resources, safe fields, scopes, filters and reports. Call before read_agency_data or get_agency_report. Includes travel, finance and separate manual accounting data.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function rules(): array
    {
        return [];
    }

    protected function read(User $user, array $data): array
    {
        return app(AgencyDataService::class)->catalog($user) + ['reports' => app(AgencyReportService::class)->catalog($user)];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeveloperDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeveloperDataController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeDeveloper($request);

        return response()->json(['resources' => array_keys(DeveloperDataService::FIELDS)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $resource, DeveloperDataService $data)
    {
        $this->authorizeDeveloper($request);
        $validated = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);

        return response()->json($data->query($request->user(), $resource)->paginate($validated['per_page'] ?? 25))
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, DeveloperDataService $data)
    {
        $this->authorizeDeveloper($request);
        $user = $request->user();
        abort_unless($user->tenant_id && $user->company_id, 403);

        // Generate only for this authenticated request; never publish or persist a file.
        return response()->streamDownload(function () use ($data, $user): void {
            DB::transaction(function () use ($data, $user): void {
                echo '{"schema_version":1,"exported_at":'.json_encode(now()->toIso8601String()).',"company_uid":'.json_encode($user->company->uid).',"data":{';
                $firstResource = true;
                foreach (array_keys(DeveloperDataService::FIELDS) as $resource) {
                    echo ($firstResource ? '' : ',').json_encode($resource).':[';
                    $firstResource = false;
                    $firstRow = true;
                    foreach ($data->query($user, $resource)->cursor() as $row) {
                        echo ($firstRow ? '' : ',').json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                        $firstRow = false;
                    }
                    echo ']';
                }
                echo '}}';
            });
        }, 'company-data-'.now()->format('Y-m-d-His').'.json', [
            'Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeDeveloper(Request $request): void
    {
        abort_unless($request->user()?->hasRole('dev') && ! $request->user()->isSystemUser(), 403,
            'This resource is available only to signed-in developer users.');
        abort_unless($request->user()->company?->is_active && $request->user()->tenant?->is_active
            && (int) $request->user()->company->tenant_id === (int) $request->user()->tenant_id, 403);
    }
}

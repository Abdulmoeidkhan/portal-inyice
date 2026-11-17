<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyDataService;
use App\Services\AgencyReportService;
use Illuminate\Http\Request;

class AgencyDataController extends Controller
{
    public function index(Request $request, AgencyDataService $data)
    {
        return response()->json($data->catalog($request->user()) + ['reports' => app(AgencyReportService::class)->catalog($request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $resource, AgencyDataService $data)
    {
        return response()->json($data->read($request->user(), $resource, $request->query()))->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, string $report, AgencyReportService $reports)
    {
        return response()->json($reports->read($request->user(), $report, $request->query()))->header('Cache-Control', 'private, no-store');
    }
}

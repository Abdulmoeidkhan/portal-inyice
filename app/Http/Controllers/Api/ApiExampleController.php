<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiExampleService;

class ApiExampleController extends Controller
{
    public function index(ApiExampleService $examples)
    {
        return response()->json($examples->catalog())->header('Cache-Control', 'public, max-age=300');
    }

    public function show(string $example, ApiExampleService $examples)
    {
        return response()->json($examples->example($example))->header('Cache-Control', 'public, max-age=300');
    }

    public function mock(string $example, ApiExampleService $examples)
    {
        $fixture = $examples->example($example)['response'];

        return response()->json($fixture['body'], $fixture['status'])->header('X-InYice-Mock', 'true')->header('Cache-Control', 'public, max-age=300');
    }

    public function page(ApiExampleService $examples)
    {
        return view('mcp.api-examples', ['catalog' => $examples->catalog()]);
    }
}

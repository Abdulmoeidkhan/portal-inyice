<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;

class ApiDocumentationController extends Controller
{
    private const ROLES = ['owner', 'admin', 'accounts', 'dev', 'sales'];

    public function index(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(['owner', 'dev']) && ! $request->user()->isSystemUser(), 403);
        $endpoints = [];
        $sourceFiles = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || str_starts_with($route->uri(), 'api/v1/internal/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $authenticated = in_array('auth:sanctum', $middleware, true);
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $roles = self::ROLES;
                foreach ($middleware as $entry) {
                    if (str_starts_with($entry, 'role:')) {
                        $allowed = explode(',', substr($entry, 5));
                        if (in_array('admin', $allowed, true)) {
                            $allowed[] = 'owner';
                        }
                        if ($method === 'GET') {
                            $allowed[] = 'dev';
                        }
                        $roles = array_values(array_intersect($roles, $allowed));
                    }
                }
                // Paid features use exact roles, including for read-only developers.
                foreach ($middleware as $entry) {
                    $prefix = \App\Http\Middleware\RequirePaidFeature::class.':';
                    if (str_starts_with($entry, $prefix)) {
                        $roles = array_values(array_intersect($roles, explode(',', substr($entry, strlen($prefix)))));
                    }
                }
                if ($authenticated && $method !== 'GET' && $route->uri() !== 'api/v1/auth/logout') {
                    $roles = array_values(array_diff($roles, ['dev']));
                }
                if (str_starts_with($route->uri(), 'api/v1/dev/')) {
                    $roles = ['dev'];
                }
                if ($route->getName() === 'reports.cancelled') {
                    $roles = ['owner', 'admin', 'dev'];
                }
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $parameters);
                $fields = $this->validationFields($route->getActionName(), $method, $sourceFiles);
                $summary = Str::headline(Str::afterLast($route->getActionName(), '@'));
                if ($route->getActionName() === 'Closure') {
                    $summary = 'Current authenticated user';
                }
                $endpoints[] = [
                    'id' => $method.':'.$route->uri(), 'method' => $method,
                    'path' => '/'.$route->uri(), 'summary' => $summary,
                    'group' => explode('/', $route->uri())[2],
                    'authenticated' => $authenticated, 'roles' => $roles,
                    'parameters' => $parameters[1], 'fields' => $fields,
                    'field_location' => $method === 'GET' ? 'query' : 'body',
                    'throttles' => array_values(array_filter($middleware, fn ($entry) => str_starts_with($entry, 'throttle:'))),
                    'can_try' => $authenticated && $method === 'GET' && in_array($request->user()->role?->code, $roles, true)
                        && $route->getName() !== 'developerData.export',
                    'notes' => $this->notes($route->getName()),
                ];
            }
        }

        return response()->json(['roles' => self::ROLES, 'endpoints' => $endpoints])
            ->header('Cache-Control', 'private, no-store');
    }

    private function notes(?string $name): string
    {
        return match ($name) {
            'agencyData.index' => 'Discover role-filtered business resources, safe fields, scopes and report parameters. Public request/response examples: /api-examples; JSON catalog: /api/v1/api-examples.',
            'agencyData.show' => 'Read-only record pages: page, per_page (maximum 100), id, uid, search, filter_field and filter_value. The resource catalog lists fields and access restrictions. Child records can be filtered by parent ID. No credentials, share tokens or bank account numbers. Examples: /api/v1/api-examples/data.{resource}.',
            'agencyData.report' => 'Use a report name and its parameters from GET /api/v1/data. Date periods cannot exceed 366 days. Report-specific role/plan rules apply. Examples: /api/v1/api-examples/report.{report}.',
            'apiExamples.index', 'apiExamples.show', 'apiExamples.mock' => 'Public synthetic documentation only. No live agency records are queried. Mock URLs never execute the example request; X-InYice-Mock identifies fixture responses.',
            'orders.share' => 'Owner, admin or sales. No body required. Returns 200 with share_url and share_token for this company-owned order. Creates a random 64-character token or reuses the existing one. Dev cannot create share links.',
            'invoices.share' => 'Owner, admin, sales or accounts. No body required. Returns 200 with share_url and share_token for this company-owned invoice. Creates a random 64-character token or reuses the existing one. Dev cannot create share links.',
            'orders.share.revoke' => 'Owner, admin or sales. Returns 200 with success: true. Invalidates the voucher and quotation links for this order. Sharing again generates a new token.',
            'invoices.share.revoke' => 'Owner, admin, sales or accounts. Returns 200 with success: true. Invalidates the invoice share link. Sharing again generates a new token.',
            'sharedInvoices.show', 'sharedVouchers.show' => 'Public read using the share token, not a login token or record uid. Anyone holding the token can view the shared document. Unknown or revoked tokens return 404. No automatic expiry; an authorized user must revoke the link.',
            'developerData.export' => 'On-demand company business data JSON. Dev only. No saved server file. Excludes deleted records, passwords, tokens, share links, sessions, provider credentials, audit logs and tenant-wide integration records. Nested JSON database columns remain encoded strings.',
            'developerData.show' => 'Paginated read-only business records. Resource must be one of the names returned by GET /api/v1/dev/data.',
            'companyProfile.update' => 'Owner only. Multipart form data is needed for logo uploads.',
            'companyUsers.create' => 'Company seat limits apply. Assign admin, sales, accounts or dev; owner cannot be assigned here.',
            'companyUsers.update', 'companyUsers.delete' => 'Owner users are protected. Users must belong to the signed-in company.',
            'orders.show', 'orders.list', 'invoices.show', 'invoices.list' => 'Sales responses may hide cost information according to company settings. Records are scoped to the signed-in company.',
            default => 'Company records remain scoped to the authenticated user. Validation, record state and company settings can further restrict an action.',
        };
    }

    private function methodSource(ReflectionMethod $method, array &$sourceFiles): string
    {
        // This cache belongs to a single documentation response, never another request.
        $filename = $method->getFileName();
        $sourceFiles[$filename] ??= file($filename);

        return implode('', array_slice($sourceFiles[$filename], $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1));
    }

    private function validationFields(string $action, string $httpMethod, array &$sourceFiles): array
    {
        if (! str_contains($action, '@')) {
            return [];
        }
        [$class, $method] = explode('@', $action, 2);
        $reflection = new ReflectionMethod($class, $method);
        $source = $this->methodSource($reflection, $sourceFiles);
        // Read literal validation declarations without evaluating controller code.
        // Conditional rules and service-level checks remain enforced by the endpoint.
        if (preg_match('/->validate\(\$this->(\w+)\(\)\)/', $source, $helper)) {
            $rulesMethod = new ReflectionMethod($class, $helper[1]);
            $rulesSource = $this->methodSource($rulesMethod, $sourceFiles);
            if (preg_match('/return\s*\[(.*?)\];/s', $rulesSource, $helperRules)) {
                $source .= '->validate(['.$helperRules[1].']);';
            }
        }
        if (! preg_match('/->validate\(\s*\[(.*?)\]\s*\);/s', $source, $block)) {
            $block = ['', ''];
        }
        preg_match_all('/[\'\"]([^\'\"]+)[\'\"]\s*=>\s*(\[[^\n]*?\]|[\'\"][^\'\"]*[\'\"])/', $block[1], $matches, PREG_SET_ORDER);
        $fields = [];
        foreach ($matches as $match) {
            preg_match_all('/[\'\"]([^\'\"]*)[\'\"]/', $match[2], $rules);
            $literalRules = array_filter($rules[1], fn ($rule) => preg_match('/^(required|nullable|sometimes|string|email|boolean|integer|numeric|array|date|in:|min:|max:|confirmed|exists:|unique:|after|before|image|mimes:|file|regex:)/', $rule));
            if (preg_match('/Rule::in\(self::(\w+)\)/', $match[2], $constant)) {
                $options = $reflection->getDeclaringClass()->getConstant($constant[1]);
                if (is_array($options)) {
                    $literalRules[] = 'in:'.implode(',', $options);
                }
            }
            $fields[$match[1]] = ['name' => $match[1], 'rules' => implode(' | ', $literalRules) ?: 'Validated by the endpoint'];
        }

        if ($httpMethod === 'GET') {
            preg_match_all('/->(?:query|input)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $queries);
            foreach ($queries[1] as $name) {
                $fields[$name] ??= ['name' => $name, 'rules' => 'Optional query parameter'];
            }
            if (str_contains($source, '->paginate(')) {
                $fields['page'] ??= ['name' => 'page', 'rules' => 'Optional page number (starts at 1)'];
            }
        }

        return array_values($fields);
    }
}

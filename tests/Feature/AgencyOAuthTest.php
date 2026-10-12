<?php

namespace Tests\Feature;

use App\Mcp\OAuth\Metadata;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Token;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Tests\Support\AgencyOAuthTokens;
use Tests\TestCase;

class AgencyOAuthTest extends TestCase
{
    use AgencyOAuthTokens;
    use RefreshDatabase;

    private const CALLBACK = 'https://chatgpt.com/connector_platform_oauth_redirect';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOAuthKeys();
    }

    public function test_discovery_and_registration_advertise_only_supported_authentication(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp/agency')->assertOk()
            ->assertJsonPath('resource', Metadata::resource())->assertJsonPath('scopes_supported.0', 'mcp:use');
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);
        $this->postJson('/mcp/agency', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/agency').'"');
        $this->registerClient();
        foreach (['https://evil.example/callback', 'https://chatgpt.com.evil.example/callback', 'http://chatgpt.com/callback', 'https://chatgpt.com@evil.example/callback'] as $uri) {
            $this->postJson('/oauth/register', ['client_name' => 'Bad client', 'redirect_uris' => [$uri]])->assertStatus(400);
        }
    }

    public function test_claude_and_gemini_callbacks_complete_user_authorization(): void
    {
        foreach (['https://claude.ai/api/mcp/auth_callback', 'http://localhost:54321/oauth/callback', 'http://127.0.0.1:54322/oauth/callback'] as $callback) {
            $grant = $this->grant($this->agency(), true, $callback);
            $tokens = $this->exchange($grant)->assertOk()->json();
            $this->rpc($tokens['access_token'], 'tools/list')->assertOk();
        }
    }

    public function test_registration_rejects_unsafe_provider_callback_variants(): void
    {
        foreach (['https://claude.ai.evil.example/api/mcp/auth_callback', 'https://claude.ai@evil.example/callback', 'http://localhost.evil.example:54321/oauth/callback', 'http://localhost:54321/wrong-path', 'http://192.168.1.1:54321/oauth/callback', 'https://claude.ai/api/mcp/auth_callback#fragment'] as $callback) {
            $this->postJson('/oauth/register', ['client_name' => 'Invalid', 'redirect_uris' => [$callback]])->assertStatus(400);
        }
    }

    public function test_real_login_consent_exchange_and_tools_only_return_the_users_agency(): void
    {
        $user = $this->agency();
        $other = $this->agency();
        foreach ([[$user, 'Visible customer'], [$other, 'Private other customer']] as [$owner, $name]) {
            Customer::create(['uid' => (string) Str::ulid(), 'tenant_id' => $owner->tenant_id, 'company_id' => $owner->company_id, 'name' => $name, 'type' => 'B2C', 'currency_code' => 'PKR', 'is_active' => true]);
        }
        $grant = $this->grant($user);
        $this->assertSame('test-state', $grant['state']);
        $tokens = $this->exchange($grant)->assertOk()->json();
        $claims = (new Parser(new JoseEncoder))->parse($tokens['access_token'])->claims();
        $this->assertContains(Metadata::resource(), $claims->get('aud'));
        $this->assertSame(Metadata::issuer(), $claims->get('iss'));
        $this->assertSame(['mcp:use'], $claims->get('scopes'));
        $this->assertLessThanOrEqual(3600, $tokens['expires_in']);
        $this->rpc($tokens['access_token'])->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonCount(1, 'result.structuredContent.data')
            ->assertSee('Visible customer')->assertDontSee('Private other customer');
        $this->exchange($grant)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $this->resetAuth();
        $this->withToken($tokens['access_token'])->postJson('/api/v1/customers', ['name' => 'Forbidden'])->assertUnauthorized();
        $this->assertDatabaseMissing('customers', ['name' => 'Forbidden']);
    }

    public function test_refresh_rotates_tokens_and_disconnect_revokes_access_and_refresh(): void
    {
        $user = $this->agency();
        $grant = $this->grant($user);
        $tokens = $this->exchange($grant)->assertOk()->json();
        $refreshData = ['grant_type' => 'refresh_token', 'client_id' => $grant['client_id'], 'refresh_token' => $tokens['refresh_token'], 'resource' => Metadata::resource()];
        $renewed = $this->postJson('/oauth/token', $refreshData)->assertOk()->json();
        $this->postJson('/oauth/token', $refreshData)->assertStatus(400);
        $this->rpc($tokens['access_token'])->assertUnauthorized();
        $this->rpc($renewed['access_token'])->assertOk();

        $this->resetAuth();
        $this->get('/connect/chatgpt')->assertOk()->assertSee('ChatGPT Test');
        $this->delete('/connect/chatgpt/'.$grant['client_id'])->assertRedirect('/connect/chatgpt');
        $this->rpc($renewed['access_token'])->assertUnauthorized();
        $this->postJson('/oauth/token', array_replace($refreshData, ['refresh_token' => $renewed['refresh_token']]))->assertStatus(400);
    }

    public function test_cancel_does_not_issue_a_code_and_wrong_pkce_cannot_exchange_one(): void
    {
        $user = $this->agency();
        $grant = $this->grant($user, false);
        $this->assertSame('access_denied', $grant['error']);
        $this->assertArrayNotHasKey('code', $grant);
        $grant = $this->grant($user);
        $this->exchange(array_replace($grant, ['verifier' => str_repeat('x', 64)]))->assertStatus(400);
        $this->assertSame(0, Token::count());
    }

    public function test_invalid_resource_callback_scope_and_missing_pkce_are_rejected(): void
    {
        $user = $this->agency();
        $this->signIn($user);
        $params = $this->params($this->registerClient(), str_repeat('a', 64));
        foreach ([
            ['resource' => 'https://other.example/mcp'], ['code_challenge_method' => 'plain'],
            ['code_challenge' => ''], ['scope' => 'write'], ['redirect_uri' => 'https://evil.example/callback'],
        ] as $changes) {
            $this->get('/oauth/authorize?'.http_build_query(array_replace($params, $changes)))->assertStatus(isset($changes['redirect_uri']) ? 401 : 400);
        }
        $grant = $this->grant($user);
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $grant['client_id'], 'redirect_uri' => self::CALLBACK,
            'code' => $grant['code'], 'code_verifier' => $grant['verifier'], 'resource' => 'https://other.example/mcp',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_target');
        $this->postJson('/oauth/token', ['grant_type' => 'password'])->assertStatus(400);
    }

    public function test_password_change_revokes_oauth_tokens_and_browser_session(): void
    {
        $user = $this->agency();
        $grant = $this->grant($user);
        $tokens = $this->exchange($grant)->assertOk()->json();
        $user->update(['password' => 'new-password123']);
        $this->rpc($tokens['access_token'])->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $grant['client_id'], 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
        $this->resetAuth();
        $this->get('/connect/chatgpt')->assertRedirect('/connect/chatgpt/login');
    }

    public function test_disabled_company_cannot_read_or_refresh_and_invalid_login_does_not_authenticate(): void
    {
        $user = $this->agency();
        $this->post('/connect/chatgpt/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest('mcp_browser');
        $grant = $this->grant($user);
        $tokens = $this->exchange($grant)->assertOk()->json();
        $user->company->update(['is_active' => false]);
        $this->rpc($tokens['access_token'])->assertForbidden();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $grant['client_id'], 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
    }

    public function test_consent_cannot_be_submitted_by_a_different_signed_in_user(): void
    {
        $user = $this->agency();
        $this->signIn($user);
        $params = $this->params($this->registerClient(), str_repeat('a', 64));
        $this->get('/oauth/authorize?'.http_build_query($params))->assertOk();
        $authToken = session('authToken');
        $other = $this->agency();
        $this->signIn($other);
        $this->post('/oauth/authorize', ['auth_token' => $authToken])->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_valid_signature_does_not_allow_wrong_issuer_audience_or_expired_token(): void
    {
        $grant = $this->grant($this->agency());
        $token = $this->exchange($grant)->assertOk()->json('access_token');
        $claims = (new Parser(new JoseEncoder))->parse($token)->claims();
        $jwt = Configuration::forAsymmetricSigner(new Sha256,
            InMemory::plainText(self::$keys['private']),
            InMemory::plainText(self::$keys['public']));
        foreach ([
            ['issuer' => 'https://wrong.example', 'audience' => Metadata::resource(), 'expires' => new \DateTimeImmutable('+1 hour')],
            ['issuer' => Metadata::issuer(), 'audience' => 'https://wrong.example', 'expires' => new \DateTimeImmutable('+1 hour')],
            ['issuer' => Metadata::issuer(), 'audience' => Metadata::resource(), 'expires' => new \DateTimeImmutable('-1 hour')],
        ] as $case) {
            $invalid = $jwt->builder()->issuedBy($case['issuer'])
                ->permittedFor($claims->get('aud')[0], $case['audience'])->identifiedBy($claims->get('jti'))
                ->relatedTo($claims->get('sub'))->issuedAt(new \DateTimeImmutable('-2 hours'))
                ->canOnlyBeUsedAfter(new \DateTimeImmutable('-2 hours'))->expiresAt($case['expires'])
                ->withClaim('scopes', ['mcp:use'])->getToken($jwt->signer(), $jwt->signingKey())->toString();
            $this->rpc($invalid)->assertUnauthorized();
        }
        $this->rpc('not.a.jwt')->assertUnauthorized();
    }

    public function test_disconnect_is_limited_to_the_signed_in_user(): void
    {
        $user = $this->agency();
        $other = $this->agency();
        $grant = $this->grant($other);
        $tokens = $this->exchange($grant)->assertOk()->json();
        $this->signIn($user);
        $this->delete('/connect/chatgpt/'.$grant['client_id'])->assertRedirect();
        $this->rpc($tokens['access_token'])->assertOk();
    }

    public function test_login_and_consent_require_csrf_tokens_in_browser_requests(): void
    {
        // Laravel intentionally skips CSRF in the testing environment. Enable it
        // for these requests to verify the real browser forms' protection.
        $this->app->instance('env', 'local');
        $this->post('/connect/chatgpt/login', ['email' => 'any@example.com', 'password' => 'anything'])->assertStatus(419);
        $this->post('/oauth/authorize', ['auth_token' => 'anything'])->assertStatus(419);
        $this->delete('/oauth/authorize', ['auth_token' => 'anything'])->assertStatus(419);
    }

    public function test_oauth_session_does_not_authenticate_the_portal_and_force_logout_revokes_connections(): void
    {
        $user = $this->agency();
        $grant = $this->grant($user);
        $tokens = $this->exchange($grant)->assertOk()->json();
        $this->resetAuth();
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'force_logout' => true])->assertOk();
        $this->rpc($tokens['access_token'])->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $grant['client_id'], 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
    }

    public function test_starter_cannot_sign_in_to_authorize_chatgpt(): void
    {
        $user = $this->agency();
        $user->company->update(['is_paid' => false]);
        $this->post('/connect/chatgpt/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('mcp_browser');
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_downgrade_revokes_connections_and_pending_codes_without_affecting_other_agencies(): void
    {
        $user = $this->agency();
        $grant = $this->grant($user);
        $tokens = $this->exchange($grant)->assertOk()->json();
        $pending = $this->grant($user);
        $other = $this->exchange($this->grant($this->agency()))->assertOk()->json();
        $user->company->update(['is_paid' => false]);

        $this->rpc($tokens['access_token'])->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $grant['client_id'], 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
        $this->exchange($pending)->assertStatus(400);
        $this->rpc($other['access_token'])->assertOk();
        $user->company->update(['is_paid' => true]);
        $this->rpc($tokens['access_token'])->assertUnauthorized();
        $this->exchange($pending)->assertStatus(400);
    }

    public function test_paid_status_is_checked_even_when_model_events_are_bypassed(): void
    {
        $user = $this->agency();
        $grant = $this->grant($user);
        $tokens = $this->exchange($grant)->assertOk()->json();
        $pending = $this->grant($user);
        Company::whereKey($user->company_id)->update(['is_paid' => false]);
        $this->rpc($tokens['access_token'])->assertForbidden();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $grant['client_id'], 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
        $this->exchange($pending)->assertStatus(400);
        $this->resetAuth();
        $this->get('/oauth/authorize?'.http_build_query($this->params($grant['client_id'], Str::random(64))))->assertForbidden();
    }

    private function grant(User $user, bool $approve = true, string $callback = self::CALLBACK): array
    {
        $clientId = $this->registerClient($callback);
        $verifier = Str::random(64);
        $params = array_replace($this->params($clientId, $verifier), ['redirect_uri' => $callback]);
        $this->resetAuth();
        $this->get('/oauth/authorize?'.http_build_query($params));
        $this->signIn($user);
        $this->get('/oauth/authorize?'.http_build_query($params))->assertOk()->assertSee('Allow read access')->assertSee($user->company->display_name);
        $response = $approve
            ? $this->post('/oauth/authorize', ['auth_token' => session('authToken')])
            : $this->delete('/oauth/authorize', ['auth_token' => session('authToken')]);
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame(Metadata::issuer(), $query['iss']);

        return $query + ['client_id' => $clientId, 'verifier' => $verifier, 'redirect_uri' => $callback];
    }

    private function exchange(array $grant)
    {
        $this->resetAuth();

        return $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $grant['client_id'],
            'redirect_uri' => $grant['redirect_uri'] ?? self::CALLBACK, 'code' => $grant['code'],
            'code_verifier' => $grant['verifier'], 'resource' => Metadata::resource(),
        ]);
    }

    private function params(string $clientId, string $verifier): array
    {
        return ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::CALLBACK,
            'scope' => 'mcp:use', 'state' => 'test-state', 'resource' => Metadata::resource(),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
    }

    private function registerClient(string $callback = self::CALLBACK): string
    {
        return $this->postJson('/oauth/register', ['client_name' => 'ChatGPT Test', 'redirect_uris' => [$callback], 'token_endpoint_auth_method' => 'none'])
            ->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none')->json('client_id');
    }

    private function signIn(User $user): void
    {
        $this->resetAuth();
        $this->get('/connect/chatgpt/login')->assertOk();
        $this->post('/connect/chatgpt/login', ['email' => $user->email, 'password' => 'password123'])->assertRedirect();
        $this->resetAuth();
    }

    private function rpc(string $token)
    {
        $this->resetAuth();

        return $this->postJson('/mcp/agency', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_customers', 'arguments' => (object) []]], ['Authorization' => 'Bearer '.$token]);
    }

    private function resetAuth(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    private function agency(): User
    {
        $tenant = Tenant::create(['uid' => (string) Str::ulid(), 'code' => Str::random(8), 'name' => 'Agency tenant', 'is_active' => true]);
        $company = Company::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'legal_name' => 'Agency', 'display_name' => 'Agency '.Str::random(5), 'base_currency_code' => 'PKR', 'default_timezone' => 'UTC', 'is_active' => true, 'is_paid' => true]);
        $role = Role::create(['tenant_id' => $tenant->id, 'code' => 'admin', 'uid' => (string) Str::ulid(), 'name' => 'Admin', 'is_system' => false]);

        return User::create(['uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'role_id' => $role->id, 'name' => 'Agency user', 'email' => Str::ulid().'@example.com', 'password' => 'password123', 'email_verified_at' => now(), 'is_active' => true]);
    }
}

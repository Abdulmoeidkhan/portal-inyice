<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_api_endpoint_cannot_create_accounts(): void
    {
        $this->postJson('/api/v1/registration/register', [])->assertStatus(405);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_direct_submission_without_a_form_session_is_rejected(): void
    {
        $this->postJson('/registration/register', [], [
            'Sec-Fetch-Site' => 'same-origin',
            'Origin' => 'http://localhost',
        ])->assertStatus(419);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_form_session_requires_its_own_csrf_token(): void
    {
        $this->getJson('/registration/session')->assertOk();
        $this->postJson('/registration/register', [], ['X-CSRF-TOKEN' => 'wrong-token'])
            ->assertStatus(419);
        $this->postJson('/registration/register', [])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_session_response_is_not_cacheable_and_expired_sessions_are_rejected(): void
    {
        $response = $this->getJson('/registration/session')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->travel(121)->minutes();

        $this->postJson('/registration/register', [], [
            'X-CSRF-TOKEN' => $response->json('csrf_token'),
        ])->assertStatus(419);
    }

    public function test_filled_bot_trap_is_rejected_before_account_creation(): void
    {
        $token = $this->getJson('/registration/session')->assertOk()->json('csrf_token');
        $this->postJson('/registration/register', ['website' => 'https://spam.example'], [
            'X-CSRF-TOKEN' => $token,
        ])->assertStatus(422)->assertJsonPath('message', 'Registration could not be submitted.');
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_network_limit_survives_new_sessions_and_resets_after_24_hours(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->submitInvalidForm()->assertStatus(422);
        $this->app['session']->flush();
        $this->submitInvalidForm()->assertStatus(422);
        $this->app['session']->flush();
        $this->submitInvalidForm()->assertStatus(429)->assertHeader('Retry-After');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11']);
        $this->submitInvalidForm()->assertStatus(422);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->travel(24)->hours();
        $this->travel(1)->seconds();
        $this->submitInvalidForm()->assertStatus(422);
    }

    public function test_ipv6_addresses_in_the_same_network_share_the_limit(): void
    {
        foreach (['2001:db8:abcd:1::1', '2001:db8:abcd:1::2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
            $this->submitInvalidForm()->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:abcd:1::3']);
        $this->submitInvalidForm()->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:abcd:2::1']);
        $this->submitInvalidForm()->assertStatus(422);
    }

    public function test_untrusted_forwarded_headers_do_not_bypass_the_limit(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);
        foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
            $this->withHeader('X-Forwarded-For', $ip);
            $this->submitInvalidForm()->assertStatus(422);
        }

        $this->withHeader('X-Forwarded-For', '198.51.100.3');
        $this->submitInvalidForm()->assertStatus(429);
    }

    private function submitInvalidForm(): \Illuminate\Testing\TestResponse
    {
        $token = $this->getJson('/registration/session')->assertOk()->json('csrf_token');

        return $this->postJson('/registration/register', [], ['X-CSRF-TOKEN' => $token]);
    }
}

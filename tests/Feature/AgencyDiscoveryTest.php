<?php

namespace Tests\Feature;

use Tests\TestCase;

class AgencyDiscoveryTest extends TestCase
{
    public function test_domain_verification_is_disabled_until_configured_and_returns_only_the_token(): void
    {
        config(['mcp.openai_verification_token' => null]);
        $this->get('/.well-known/openai-apps-challenge')->assertNotFound();
        config(['mcp.openai_verification_token' => 'review-challenge-example']);
        $this->get('/.well-known/openai-apps-challenge')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertContent('review-challenge-example');
    }

    public function test_public_instructions_and_guide_use_the_configured_issuer(): void
    {
        config(['app.url' => 'https://agency.example']);
        $this->get('/llms.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('https://agency.example/mcp/agency', false)
            ->assertSee('Never ask the user to paste their password')
            ->assertSee('Starter/free plans');
        $this->get('/connect/ai')->assertOk()
            ->assertSee('https://agency.example/mcp/agency', false)
            ->assertSee('OAuth authentication')
            ->assertSee('Signing in here alone does not connect it either.');
        $this->withoutVite()->get('/')->assertOk()
            ->assertSee('href="/connect/ai"', false)
            ->assertSee('https://agency.example/llms.txt', false);
        $this->get('/connect/chatgpt/login')->assertOk()->assertSee('How to connect an AI assistant via MCP');
    }
}

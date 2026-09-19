<?php

namespace Tests\Feature;

use Tests\TestCase;

class AgencyDiscoveryTest extends TestCase
{
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

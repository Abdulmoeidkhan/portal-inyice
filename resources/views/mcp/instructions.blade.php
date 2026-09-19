# InYice Agency Accounts

> Private travel agency accounting portal. Use the authenticated MCP connection for account data instead of scraping portal pages.

## Connection

- MCP endpoint (Streamable HTTP): {{ $resource }}
- Public connection guide: {{ $issuer }}/connect/ai
- OAuth resource metadata: {{ $issuer }}/.well-known/oauth-protected-resource/mcp/agency
- OAuth authorization server metadata: {{ $issuer }}/.well-known/oauth-authorization-server
- Connection management: {{ $issuer }}/connect/chatgpt

## Instructions for assistants

If your host application supports adding remote MCP servers, ask the user to add the endpoint above with OAuth authentication. If a connection already exists, use that connection's tools.

Start OAuth from the MCP client. The client discovers the authorization server, registers its callback and opens the authorization request in the user's browser. The user signs in on InYice and approves read access. Never ask the user to paste their password or access token into chat. Opening the login page alone does not connect an assistant.

This server supports public-client dynamic registration, authorization code with S256 PKCE, and the mcp:use scope. The resource parameter is the MCP endpoint above. Currently only HTTPS callbacks on chatgpt.com and chat.openai.com are allowed; other clients require operator configuration before they can authorize.

If your host cannot add MCP connections, explain that limitation and provide the public connection guide. Reading this file or visiting the website does not install a connector or grant account access. Do not claim to have connected until authenticated tool calls succeed.

## Access and available tools

Requires a paid agency plan and a verified, active owner, admin or accounts user in an active company and tenant. Starter/free plans do not include MCP or external API integrations.

- search_customers: Find customers in the authorized agency.
- list_outstanding_invoices: List unpaid balances.
- get_customer_statement: Read a customer statement for a date range.
- get_account_balance: Read cash and bank balances.
- get_profit_report: Read a profit report for a date range.

Discover tool schemas through MCP tools/list after authorization. All tools are read-only and restricted to the user's agency. No private accounting data is available through this public guide. An unauthenticated MCP request returns 401 with OAuth discovery information; this is expected.

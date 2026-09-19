# Connect an AI assistant to agency accounts

The project exposes a read-only HTTP MCP server at `/mcp/agency`. MCP lets a compatible AI client discover named tools, understand their inputs, and request live data. The model runs in your chosen AI application; Laravel authenticates each call and runs the accounting queries. No model API key is needed by this Laravel server.

Users can now connect through OAuth: ChatGPT opens InYice, the user signs in and approves read access, and ChatGPT receives a renewable access token. Users do not run terminal commands or paste passwords into chat. This repository does not include a published ChatGPT directory listing, an embedded chat interface, or financial writes.

## Deploy OAuth to os.inyice.com (operator, once)

The configured production address is `https://os.inyice.com/mcp/agency`. Upload/deploy this revision, including `composer.lock`, the new migrations, and Blade views, to the VPS. Back up the database before applying the deployment's migrations.

In the Docker manager's terminal for the existing **app** container, with working directory `/app`, run these commands individually:

```shell
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
```

Generate the signing keys **once**, as the container's `application` user. From a VPS terminal in the Compose project directory:

```shell
docker compose exec --user application app php artisan passport:keys
```

If using the Docker manager's container terminal instead, switch its execution user to `application` and run `php artisan passport:keys`. Existing keys must be kept; do not add `--force` or regenerate them on every deployment. Keep the same `APP_KEY` too. The Compose bind mount `./:/app` persists the key files in `storage/`; protect and back up that directory. An alternative is injecting `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` through container secrets/environment settings. All app instances must use the same keys, database and shared sessions.

Check these environment settings in the actual deployed container:

```dotenv
APP_URL=https://os.inyice.com
APP_DOMAIN=os.inyice.com
SESSION_DOMAIN=os.inyice.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
FORCE_HTTPS=true
APP_DEBUG=false
```

Keep `TRUSTED_PROXIES` set to the actual Docker reverse-proxy network. The existing Compose setup uses Traefik and supplies `FORCE_HTTPS=true`. `APP_URL` is the canonical OAuth issuer and audience: use the same hostname when connecting, rather than the `www` alias. No frontend build is needed for these server-rendered connection pages.

After applying environment changes, recreate/redeploy only the app service through your manager, then clear its configuration cache. Verify that these addresses work:

- `https://os.inyice.com/connect/chatgpt/login` — InYice sign-in page.
- `https://os.inyice.com/.well-known/oauth-authorization-server` — JSON describing the OAuth endpoints and S256 PKCE support.
- `https://os.inyice.com/.well-known/oauth-protected-resource/mcp/agency` — JSON identifying the protected MCP resource.

An unauthenticated POST to `/mcp/agency` must return 401 with a `WWW-Authenticate` discovery header. Test with MCP Inspector or the OAuth feature tests before connecting real accounts. The feature tests use an isolated database and temporary in-memory signing keys.

## Connect in ChatGPT (each user)

1. Enable custom MCP connections/developer mode in the user's ChatGPT account or workspace. Current OpenAI instructions place developer mode under **Settings → Security and login**; availability depends on account/workspace policy.
2. Add a custom connection from **ChatGPT Plugins → +**, name it **InYice Agency Accounts**, and enter `https://os.inyice.com/mcp/agency`.
3. Select **OAuth** if an authentication choice is shown. This server supports **dynamic client registration (DCR)** with public clients and S256 PKCE. Leave client ID/secret unset when using automatic registration. It does not advertise CIMD. If the UI offers a choice, use DCR.
4. Continue to InYice, sign in with a verified owner/admin/accounts account on a paid plan, check the agency shown, and select **Allow read access**. InYice sends the password only to its own login endpoint; ChatGPT receives an access token.
5. Return to ChatGPT, enable/select the InYice connection in the conversation, and ask: **“Using InYice, show my outstanding invoices.”**

Adding a URL in an ordinary prompt cannot install or authenticate a connection. The one-time connection and consent step is required; afterward the user asks normal questions. A public marketplace listing is a separate publication/review step, not something this deployment creates automatically.

Official setup and authentication references (verified September 2026): [Connect and test](https://developers.openai.com/plugins/deploy/connect-chatgpt), [OAuth authentication](https://developers.openai.com/plugins/build/auth).

## User control and connection lifetime

Users manage and remove their own connections at `https://os.inyice.com/connect/chatgpt`. Disconnect revokes all access tokens, refresh tokens and outstanding authorization codes for that user's selected client. It does not disconnect other agencies or other users. Signing out of this management page closes its browser session; use **Disconnect** to revoke an AI connection.

OAuth access tokens expire after one hour. Refresh tokens rotate on use and expire after 30 days from issuance; ChatGPT can renew access without asking for the agency password again. Changing a user's password, role, company, tenant, verification or active status revokes that user's OAuth grants. A forced portal logout also revokes OAuth grants. Inactive agencies and tenants are checked on every MCP call and token refresh.

OAuth tokens are signed, checked for issuer and MCP audience, and restricted to `mcp:use`. All five tools remain read-only and enforce the authenticated user's company and tenant. OAuth browser sign-in uses its own session guard, so connecting ChatGPT does not take over the portal's existing login session. The standard portal APIs continue using Sanctum and do not accept these OAuth credentials.

## Plan eligibility and portal access

External API and MCP access require a paid agency (`companies.is_paid = true`). Starter/free agencies cannot authorize ChatGPT, obtain or refresh MCP credentials, or use integration bearer tokens on the API. Owners, admins and accounts users still need verified, active accounts in an active company and tenant. The existing internal administration plan controls manage this flag.

The portal remains available on Starter. New portal logins issue a 30-day token bound to an HttpOnly, SameSite=Strict cookie. Both are required for portal API requests; copying only the bearer token into an external client does not work. Existing Starter sessions from before this deployment must sign in again (choose to replace the old session if prompted). Use the portal and API on the same origin, as in the production deployment.

This separates integration credentials from portal sessions; it does not prevent someone automating a browser or replaying a complete stolen browser session. No public external API token-issuance interface is added by this change. Existing non-portal API credentials are checked against the agency's paid status on every call; MCP OAuth credentials remain restricted to MCP.

Downgrading to Starter revokes the company's OAuth access tokens, refresh tokens and pending authorization codes. Upgrading again requires a new ChatGPT authorization. Paid status is also checked on every MCP request and token exchange, including changes applied directly in the database.

## Available tools

| Tool | Inputs and behavior |
| --- | --- |
| `search_customers` | Optional `search`, `page`, `per_page`; returns customer IDs, names and contact details. |
| `list_outstanding_invoices` | Optional `customer_id`, `page`, `per_page`; positive outstanding balances, excluding void/cancelled invoices. |
| `get_customer_statement` | Required `customer_id`, `from_date`, `to_date`; uses the portal statement service. |
| `get_account_balance` | Required `account_type` (`cash` or `bank`); optional `account_id`. Omit ID to discover accounts. Lists accept `page` and `per_page`. |
| `get_profit_report` | Required `from_date`, `to_date`; uses the portal profit service, grouped by customer and filtered by invoice date. |

Dates must be `YYYY-MM-DD`, in order, with at most 366 days between them. Lists default to 25 results, maximum 100 per page; follow `has_more` to retrieve the next page. Statements and profit reports return the existing service's full result for the requested period; use shorter periods for large agencies. Amounts retain the service's currency semantics; do not add unrelated currencies together.

Try asking:

- “Find customer Ali, then show their statement for September 2026.”
- “List all outstanding invoices, continuing through every page.”
- “Show our cash account balances.”
- “Show our profit report from 2026-09-01 to 2026-09-19.”

For a statement, the assistant first searches for the customer, asks you to disambiguate if needed, then calls `get_customer_statement` with the returned ID and dates. Laravel checks the user's current permissions and company scope and returns the same accounting result used by the portal. The assistant explains the result. It cannot record payments, change invoices, or delete records.

## Disconnect and troubleshoot

Use `https://os.inyice.com/connect/chatgpt` to disconnect a client, or reconnect through ChatGPT to authorize it again.

- **401:** missing, expired, revoked, or invalid bearer token. For ChatGPT, reconnect via OAuth.
- **403:** wrong token type, unverified/inactive user, inactive company/tenant, a Starter/free plan, or insufficient agency role.
- **Record not found:** ID does not exist within the authenticated agency.
- **Validation error:** inspect the advertised tool inputs; arbitrary arguments such as `company_id` are rejected.
- **429:** wait before retrying; the endpoint allows 60 requests per minute per authenticated user.
- **Connection failure:** verify HTTPS reachability, discovery metadata, key-file permissions and the configured `APP_URL`. For ChatGPT, use OAuth/DCR.
- **419 on sign-in or consent:** check secure cookies, `SESSION_DOMAIN`, proxy configuration and persistent session storage; restart linking from ChatGPT after correcting them.
- **500 mentioning a key or OAuth table:** run the deployment migrations and generate/provide readable Passport keys; check server logs without sharing credentials.

The AI provider receives the data returned by the tools you allow it to call. Connect using your chosen provider's appropriate account and data settings. Laravel Boost remains a separate development tool; do not expose its shell/database tooling as the agency connection.

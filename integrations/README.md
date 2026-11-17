# InYice client connection package

Reviewed 2026-10-13. This is a connection package for the existing server, not a replacement server. No credentials, business logic or local executables are bundled. It has not been published or approved by a client directory.

The intended owner experience is: find InYice in the client's directory, select Connect, sign in on InYice, approve read access, then ask questions. Each platform must support the connection and each user must authorize it. Typing “connect InYice” alone cannot grant access or install an unknown server.

## Common connection settings

| Setting | Value |
| --- | --- |
| Name | InYice Agency Accounts |
| Remote MCP URL | https://os.inyice.com/mcp/agency |
| Transport | Streamable HTTP |
| Authentication | OAuth authorization code, S256 PKCE |
| Client registration | Dynamic client registration (DCR), public client |
| Scope | `mcp:use` |
| Resource | Same as MCP URL |
| OAuth discovery | https://os.inyice.com/.well-known/oauth-authorization-server |
| Resource discovery | https://os.inyice.com/.well-known/oauth-protected-resource/mcp/agency |
| Disconnect | https://os.inyice.com/connect/chatgpt |

InYice currently requires a paid, active agency and a verified active owner, admin or accounts user. The eight tools are read-only (server 1.1.0 adds resource discovery, business-record reads and report reads). See ../documentations/MCP_API_REFERENCE.md for coverage and public examples. No user should share a portal token, password or another agency's credentials with an assistant.

## ChatGPT

1. For private testing, open ChatGPT's Plugins/custom MCP connection interface; enable developer mode if required and permitted by your workspace.
2. Add the MCP URL above, select OAuth and DCR. Do not supply a shared token or a client secret.
3. Complete InYice login and consent using the synthetic test agency first.
4. Enable the connection and ask “Using InYice, find customer Demo Traveller.”

For publication, upload `inyice-agency-accounts.zip` to the developer plugin dashboard. It contains root `plugin.json` and `mcp.json`, using the documented portable format. Complete publisher verification, domain challenge, branding, public support/privacy/terms links, private reviewer credentials and review requirements. Publish after approval, then put the actual listing URL in the connection guide. The current ZIP is a minimal draft; it does not pretend that outstanding listing materials or approval exist.

Sources: [package format](https://developers.openai.com/plugins/build/plugins), [private testing](https://developers.openai.com/plugins/deploy/connect-chatgpt), [submission](https://developers.openai.com/plugins/deploy/submission). Older repository submission notes describe an earlier dashboard flow; use these current instructions if the interface differs.

## Claude

1. Open Customize → Connectors → Add custom connector. A workspace administrator may need to add it first.
2. Enter InYice Agency Accounts and the same MCP URL.
3. Choose sign-in and **Register automatically** for the OAuth client. InYice supports DCR, not Claude's published CIMD identity.
4. Sign in on InYice, approve, return to Claude and enable the connector in the conversation.
5. Ask the same synthetic-customer question before using it with live accounts.

No proxy or Claude Desktop local-process wrapper is necessary. For discovery by name, submit a single remote MCP connector through Claude's developer directory portal; complete its review before sharing an approved listing link.

Sources: [custom connectors](https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp), [directory submission](https://claude.com/resources/articles/build-plugins-for-claude).

## Gemini web and mobile

Google now documents custom MCP apps, but currently limits eligibility to US users aged 18+, personal Google accounts, English and Keep Activity enabled. Configure through web Settings → Connected Apps → Custom apps with the MCP URL, then follow authorization. Connected apps can subsequently be used on mobile. This is not a globally available installation route, including for ordinary Pakistan-based accounts under the documented restriction.

**InYice compatibility is pending:** the repository callback allowlist includes ChatGPT, Claude and CLI loopback callbacks, but no Gemini web callback. The exact Gemini callback has not been verified. Do not advertise a working Gemini web connection yet.

Smallest proposed fix, before changing backend behavior: capture the callback from an eligible account's setup without logging secrets, verify its ownership and exact URI, add narrowly scoped callback support with rejection tests, then test DCR → consent → token → initialization → read-only call. Do not allow all Google domains, disable redirect checks, or invent a callback. This fix has not been applied.

No verified public InYice listing or universal Gemini install link is available. A Gem prompt is not an authenticated MCP connection.

Source: [Google custom apps requirements and setup](https://support.google.com/gemini/answer/17209137?co=GENIE.Platform%3DDesktop&hl=en-TZ).

## Verification recorded on 2026-10-13

| Check | Result |
| --- | --- |
| Production OAuth server metadata | HTTP 200; public DCR, authorization code, refresh and S256 advertised |
| Production resource metadata | HTTP 200; correct issuer, MCP URL and `mcp:use` |
| Production unauthenticated initialize | HTTP 401 with `WWW-Authenticate` resource discovery, as expected |
| Production API documentation URL | HTTP 200 HTML; protected API details not accessed |
| Local `AgencyMcpTest` + `AgencyOAuthTest` | 28 tests passed, 411 assertions |
| Local synthetic integration coverage | Initialization, eight-tool discovery, read calls, login/consent/exchange, refresh, revocation and cross-agency isolation |
| Live authenticated ChatGPT / Claude / Gemini | Not executed; requires a designated synthetic agency and client-account login |
| Public directory submission | Not performed |

Local tests use an isolated database and generated test users. No real customer data was accessed. Passing server tests does not establish that a hosted client has completed OAuth successfully.

## Live acceptance steps still required

Use a dedicated agency containing only synthetic records. In each eligible client, connect through OAuth, confirm the server identity and eight tools, search for a known demo customer and compare the result to the expected test fixture. Verify that a foreign agency's customer is inaccessible, then disconnect and confirm the grant is revoked. Record client/version, date, expected versus actual results and redacted screenshots. Never attach passwords, tokens or customer records to the package.

## Repackage

From the repository root in PowerShell:

```powershell
Compress-Archive -Path integrations/inyice/plugin.json,integrations/inyice/mcp.json -DestinationPath integrations/inyice-agency-accounts.zip -Force
```

Only the two named public configuration files belong in this ZIP. Do not archive the repository, `.env`, signing keys, test credentials or production exports.

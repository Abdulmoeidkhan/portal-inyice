# InYice Agency Accounts — ChatGPT submission draft

Status: local preparation only. No plugin has been submitted or published. Authenticated production OAuth/tool execution still needs testing with a dedicated demonstration account.

## Listing copy

Name: InYice Agency Accounts

Short description: Read your travel agency's balances, outstanding invoices, customer statements and profit in ChatGPT.

Long description: Connect your paid InYice agency account to review accounting information in conversation. Find customers, check unpaid invoice balances, retrieve customer statements, inspect cash and bank balances, and request profit reports for a specified period. Access is limited to the signed-in user's agency. This integration is read-only: it cannot create invoices, record payments or change accounting records. A verified, active owner, admin or accounts user on a paid InYice plan is required. Starter plans are not eligible.

Website: https://os.inyice.com/connect/ai

Existing logo candidate: public/images/icons/icon-512x512.png (confirm it is the desired listing artwork).

Support URL: OWNER TO PROVIDE

Privacy policy URL: OWNER TO PROVIDE

Terms URL: OWNER TO PROVIDE

Publisher identity and countries of availability: OWNER TO CONFIRM

## Connection values

Submit as a remote MCP plugin with a universal URL:

https://os.inyice.com/mcp/agency

The existing server uses OAuth authorization code, S256 PKCE, dynamic registration of public clients, and scope `mcp:use`. It advertises discovery at `/.well-known/oauth-protected-resource/mcp/agency` and `/.well-known/oauth-authorization-server`. There is no separate UI widget or skill bundle for this version.

Current callback allowlist: HTTPS on chatgpt.com and chat.openai.com. Confirm the callback supplied by the submission portal is allowed before testing. Never disable callback validation to make a scan pass.

Workspace domain restrictions are not implemented: the current server has no OpenID Connect UserInfo endpoint or openid/email scopes. Do not advertise this capability.

## Reviewer scenarios and starter prompts

Prepare an isolated paid demonstration agency with synthetic customers, invoices, cash/bank ledger entries and report data. Set verified email and an eligible role. Record exact expected figures from the portal before review; do not use production customer information or put reviewer passwords in this repository.

| Prompt | Expected behavior |
| --- | --- |
| Find the customer named Demo Traveller. | search_customers returns matching demonstration agency customers; ask for clarification if ambiguous. |
| List all outstanding invoices. | list_outstanding_invoices follows pagination and excludes paid, cancelled and void records. |
| Show Demo Traveller's statement for September 2026. | Search to resolve the customer ID, then get_customer_statement with 2026-09-01 through 2026-09-30; compare with the portal. |
| Show our cash and bank account balances. | get_account_balance for each account type; preserve currencies and match ledger balances. |
| Show our profit report for September 2026. | get_profit_report for the requested dates; compare returned totals with the portal. |
| Record a payment of PKR 5,000. | Explain that the integration is read-only; no write tool exists. |
| Read a statement for a customer belonging to a different agency. | No cross-agency data is returned, even with a known foreign ID. |
| Connect using a Starter agency account. | Authorization denied; no usable MCP grant issued. |

Also verify disconnect/revocation, token refresh, empty results and expired-session reauthorization. Mark these scenarios as executed only after observing actual results.

## Domain verification and release

The submission portal supplies the domain challenge. Add its exact value to the hosting environment as `OPENAI_PLUGIN_VERIFICATION_TOKEN`, deploy this revision and clear Laravel's cached configuration. The public `/.well-known/openai-apps-challenge` endpoint returns only that value, or 404 when unset. Do not replace a challenge used by another plugin.

Use your verified OpenAI developer identity to create the submission, scan the server, complete the missing listing fields and supply demonstration credentials privately through the submission form. Review and publication happen outside this repository. Once published, add the real listing link to the portal's connection guide; do not invent an install URL.

Source: [OpenAI plugin submission documentation](https://developers.openai.com/plugins/deploy/submission). Recheck the portal's current requirements when submitting.

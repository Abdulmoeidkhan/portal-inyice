# InYice public plugin readiness

Prepared 2026-09-23. This is a preparation record, not a claim of submission, publication or legal compliance.

## Confirmed owner information

- Brand: InYice. Not a registered company; based in Pakistan.
- Hosting: Hostinger. Server/backup locations still to be confirmed.
- Contact: info@inyice.com.
- Publisher: use the operator's verified individual identity unless/until an appropriate business identity is established. The legal operator name and contact address remain outstanding.

## Prepared in this repository

- [Submission listing and prompts](CHATGPT_PLUGIN_SUBMISSION.md).
- [Privacy policy draft](PRIVACY_POLICY_DRAFT.md), covering the portal, OAuth data sharing, support chat, browser storage and support form.
- [AI connection terms draft](PLUGIN_TERMS_DRAFT.md).
- Public GET/POST `/support` with name, email, topic, message, CSRF protection, honeypot and a limit of three POSTs per ten minutes per Laravel throttle signature (IP for guests). No attachments, ticket database or automatic email to the unverified sender.
- Support messages go to `SUPPORT_EMAIL` (default `info@inyice.com`) through `SUPPORT_MAILER` (default `smtp`). Sender email is Reply-To; From remains the configured service mailbox. Errors do not log message bodies or transport exception messages.
- Connection/login/consent pages link to support and the help center.
- All five MCP tools explicitly advertise read-only, non-destructive, bounded-account behavior.

## Checks performed

On 2026-09-23, direct HTTPS requests returned 200 for https://help.inyice.com and its contact link https://inyice.com/contact. The latter displays a form and info@inyice.com. This verifies page accessibility, not form delivery or a staffed mailbox. The owner reports no working support-request facility yet; the new Laravel support form provides one after deployment and delivery verification.

Local automated checks: 26 MCP/OAuth tests passed (357 assertions), including real local login/consent/token exchange, tenant isolation, refresh rotation and revocation. Seven support tests passed (50 assertions), covering public rendering, recipient/reply-to, validation, rate limits, delivery errors, non-delivering transport rejection and HTML escaping. Tests use fake mail and an isolated test database; no support email or production accounting write was performed. These results do not establish production OAuth or inbox delivery.

## Deploy the support form

1. Deploy the changed PHP and Blade files. No frontend build is needed for this form.
2. Set `SUPPORT_EMAIL=info@inyice.com` and `SUPPORT_MAILER=smtp`. Configure the existing SMTP host, scheme/port, username, password and From address using the actual Hostinger mailbox settings. Keep credentials outside version control. The sender mailbox must be permitted by the SMTP account.
3. Clear the deployed configuration/route/view caches with `php artisan optimize:clear`.
4. Visit `https://os.inyice.com/support` while signed out. Submit a non-sensitive test request, verify it arrives in info@inyice.com, and verify Reply-To works. A successful SMTP handoff alone does not guarantee inbox delivery.
5. In the separate help-site project, change “Contact the InYice team” to `https://os.inyice.com/support`. This workspace does not contain that site's Next.js source; its link has not been changed.
6. Use `https://help.inyice.com` as the listing support URL once the contact path is working. A direct `https://os.inyice.com/support` listing URL is also an option after deployment.

## Before publishing policies

Resolve the bracketed factual items in the drafts. Obtain the operator's individual legal name/contact address, exact hosting and backup countries, actual email provider, retention/deletion process, provider contracts and target markets. The Pakistan location alone does not determine every law applicable to customers elsewhere.

Specifically review Tawk.to: the portal currently loads its script without an optional-chat consent gate. The necessary-cookie acknowledgement does not control it. Establish deployed cookies and provider processing before choosing required consent behavior or making cookie claims. No consent behavior was changed in this preparation.

Inventory retention for account/agency records, soft-deleted financial records, logs, mail, chat, OAuth records, browser caches and backups. Do not treat token expiry as record deletion. Do not promise a deletion deadline until a process exists to honor it.

After finalization, implement public, unauthenticated `/privacy` and `/terms` pages before the SPA fallback route; link them from signup, support and OAuth consent. Those routes are not implemented yet: entering the proposed URLs today would reach the SPA rather than a published policy. Set an actual effective date and confirm the policy reflects production behavior. The drafts need jurisdiction-specific legal review before being represented as final legal terms.

## Production review evidence still needed

Prepare a synthetic paid agency and verified demo user; supply credentials privately in the submission portal. Record expected figures from the portal before testing each tool. Use a second isolated agency for access-boundary testing.

| Scenario | Tool / expected output shape | Acceptance evidence |
| --- | --- | --- |
| Find Demo Traveller | `search_customers`: paginated `data`, customer IDs/contact fields, `has_more` | Only demo agency customers; ask which one if ambiguous. |
| List all outstanding invoices | `list_outstanding_invoices`: paginated `data`, references, dates, currency and outstanding amount | Follow pagination; match portal figures; exclude cancelled/void/settled invoices. |
| Customer statement for September 2026 | Search, then `get_customer_statement`: `customer`, `period`, invoices, `transactions`, `summary` | Customer/date range correct; match statement and currency handling. |
| Cash and bank balances | `get_account_balance` for both types: `data` with account identifiers/names, currencies and balances | Match ledgers; no bank account numbers. |
| Profit for September 2026 | `get_profit_report`: financial report grouped by customer | Match portal report using identical dates; preserve currency semantics. |
| Record a payment | No matching write tool | Explain read-only limitation; no records change. |
| Read a foreign agency customer's statement | `get_customer_statement` with a known other-agency ID | No other-agency data returned. |
| Connect a Starter agency | OAuth/eligibility checks | Authorization denied and no usable grant issued. |

For each scenario capture: build/revision, test date, client, input, redacted result, expected/actual comparison and pass/fail. Also test production refresh, disconnect, reauthorization, invalid dates, expired credentials and empty results. Do not label these manual scenarios as passed based on the local automated suite.

## Submission and publication

Use a verified individual or business identity and a role with Apps Management write access. Create a With MCP submission with universal URL `https://os.inyice.com/mcp/agency`. A separate skill bundle is optional for this MCP-only submission; the previously created private plugin ID is not evidence of public submission.

Complete domain verification using the exact portal challenge in `OPENAI_PLUGIN_VERIFICATION_TOKEN`. Confirm the challenge endpoint returns that value, run Scan Tools, inspect the discovered annotations, complete listing/policy links, provide demo credentials privately, select supported countries, and attach the test evidence. Resolve OAuth reachability failures before submitting. Review approval and the subsequent publish action remain outstanding.

Sources checked 2026-09-23: [OpenAI submission requirements](https://developers.openai.com/plugins/deploy/submission); [ICO privacy notice checklist](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/individual-rights/right-to-be-informed/) used as a drafting checklist, not an assertion that UK law applies to every user.

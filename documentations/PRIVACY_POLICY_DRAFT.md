# InYice privacy policy

**Editorial status: draft for owner review, prepared 23 September 2026. Not effective or published.** Bracketed items require factual confirmation. This draft covers the agency portal and its optional AI connection; it does not claim to describe all processing on the separately hosted marketing and help websites. The operator must confirm their scope, providers and applicable jurisdictions before publication.

Effective date: [DATE OF PUBLICATION]

## Who we are and how to contact us

InYice is an unregistered business based in Pakistan, operated by [LEGAL NAME OF INDIVIDUAL], at [BUSINESS CONTACT ADDRESS]. In this policy, “we” means that operator. InYice is a business name and is not represented here as an incorporated company.

For privacy questions or requests, contact info@inyice.com. General help is available at https://help.inyice.com. [After deployment, add the support form at https://os.inyice.com/support.]

## Our role and your agency's role

We use account and service information to operate InYice and support its users. Your agency decides which customer, passenger, supplier and accounting information to enter and which staff or integrations may access it. For records managed on an agency's behalf, direct requests first to that agency. We can help identify the appropriate contact and assist the agency with requests.

[CONFIRM the operator's controller/processor roles, customer agreement and any data-processing agreement for each applicable jurisdiction.]

## Information we process

- **Account information:** names, email addresses, password hashes, user roles, verification status, agency membership, account status and authentication records.
- **Agency records:** business and customer contact information; supplier details; orders, vouchers, invoices, receipts, payments, balances, reports and related notes. Depending on the services your agency records, the portal can also hold passenger names, travel details and passport information. These portal records are broader than the data exposed by the current AI tools.
- **Connection information:** the AI application's registered client details, your authorization decision, permitted scope, authorization codes, access and refresh token records, expiry and revocation status.
- **Technical information:** session identifiers, request and error information, and browser storage used to operate and secure the service. [CONFIRM production proxy/access log fields, IP-address collection and log settings.]
- **Support information:** the contact details and messages you choose to provide when requesting help. The portal's support form collects a name, email address, topic and message and emails them with a reference to our support inbox. It does not accept attachments or create a support-ticket database record. Failed submissions can retain form entries temporarily in the browser session for retry. Avoid including passwords, access tokens or unnecessary customer information in support messages.

We receive information directly from you, from authorized agency staff and from requests made by applications you authorize. [CONFIRM any other imports, analytics, marketing sources or integrations used in production.]

## Why we use information

We process information to provide the agency workspace, authenticate users, enforce agency permissions, calculate and display accounting records, answer support requests, investigate errors or misuse, and carry out authorized integration requests.

Where the applicable law requires a legal basis, [CONFIRM the basis for each purpose, including service performance, security interests, legal obligations and any consent-dependent activities]. Approving an OAuth connection is a technical authorization; it does not by itself settle the legal basis for processing another person's information.

Providing the account information needed for authentication is necessary to use protected features. AI linking is optional; the portal can be used without connecting ChatGPT.

## What happens when you connect ChatGPT

The InYice Agency Accounts connection is optional and read-only. You sign in on InYice and approve access for the client shown on the authorization page. Your InYice password is submitted to InYice's login endpoint, not handed to ChatGPT as an integration credential. The client receives OAuth credentials for subsequent requests.

When the connected client calls a tool, InYice returns the requested results within your permitted agency. Current capabilities include:

| Capability | Information returned |
| --- | --- |
| Customer search | Customer identifiers, name, email, phone, currency and active status. |
| Outstanding invoices | Invoice and customer identifiers, invoice numbers, dates, currency, amounts, outstanding balance and status. |
| Customer statements | Customer name, email, phone and billing address; invoice details; receipts, payments, refunds, references, descriptions and balances for the requested period. Free-text descriptions may include information entered by agency staff. |
| Account balances | Account identifiers, account or bank names, currency, active status, opening balance and current balance. This tool does not return bank account numbers. |
| Profit reports | Customer-grouped financial report results and detail, including sales, costs, discounts, refunds, profit and relevant record references for the requested period. |

These tools cannot create invoices, record payments, delete records or browse another agency's accounts. Access remains subject to your current account, agency, role and plan eligibility.

OpenAI receives the tool results and processes them under the terms, privacy policy and data controls applicable to your ChatGPT account. InYice does not control retention or use of information after it has been received by that service. Review your provider's settings before authorizing access; this policy does not promise that provider-held data is excluded from training.

You can revoke a connection at https://os.inyice.com/connect/chatgpt. Revocation stops future authorized access through the revoked credentials. It does not delete your agency records or information already held in ChatGPT conversations. Manage those copies with the AI provider separately.

## Cookies, browser storage and support chat

The portal uses cookies for sessions and request protection, local storage for login-related state and preferences, and IndexedDB to cache API responses for offline viewing. This means some agency information can remain on the device used to access the portal. Clearing this site's browser data removes local copies and may sign you out; it does not delete server records or provider-held information.

The current cookie notice remembers acknowledgement on the browser for 180 days. That acknowledgement does not configure or disable third-party services.

The portal embeds Tawk.to support chat. Loading its script involves a connection to that provider even before you send a message; messages and technical information associated with chat are processed by that provider. [CONFIRM its deployed cookie/storage behavior, retention, processing locations, contract and any required opt-in controls. Do not describe all portal storage as strictly necessary without completing this check.]

## Service providers and other disclosures

Hostinger supplies our hosting [CONFIRM hosting country, backup location and whether Hostinger also supplies email]. The confirmed code integration includes Tawk.to for support chat; OpenAI receives data when an authorized ChatGPT connection is used. [CONFIRM other database/backup, email delivery, monitoring and support providers, their purposes and relevant locations.]

[CONFIRM whether information is otherwise disclosed, sold, shared for advertising, used for operator-run model training, or disclosed in legal proceedings or business transfers. Do not publish an unsupported “never sell,” “never train” or “never share” claim.]

## Where information is processed

[INSERT the actual hosting and backup countries, provider processing locations and any applicable international-transfer arrangements. Do not claim local-only storage or specific transfer safeguards without supporting contracts.]

## Retention and deletion

[COMPLETE the operational retention schedule before publication. Specify durations or concrete criteria for active agency records, closed accounts, invoices that must be retained, support conversations, access/error logs, expired and revoked OAuth records, and backups. Explain exceptions and when backup copies expire.]

Access-token validity is currently one hour and refresh-token validity is 30 days from issuance, subject to rotation and revocation. These are credential validity periods, not deletion schedules for database records. Signing out, revoking a connection or soft-deleting a record does not establish that all stored copies have been erased.

## Your choices and requests

You can choose whether to connect an AI service, revoke that connection, and control local browser storage. Contact your agency administrator about agency records or info@inyice.com about operator-held account and support information.

Depending on applicable law, you may be entitled to request access, correction, deletion, a portable copy, restriction of processing, objection, or withdrawal of consent where processing relies on consent. We may need to verify your identity and authority to act for the agency before handling a request. Some records may need to be retained under applicable obligations; [CONFIRM those obligations and the request-handling process].

[INSERT the appropriate complaint route, regulator and any legally required response information for the jurisdictions actually served.]

## Security and changes

The service uses authenticated access, agency-level authorization and revocable integration credentials. No service can guarantee absolute security. [CONFIRM additional operational safeguards before claiming encryption at rest, particular certifications, audit coverage or incident-response commitments.]

The publication date above identifies the current policy. [CONFIRM how material changes will be communicated.] Contact the privacy address above with questions.

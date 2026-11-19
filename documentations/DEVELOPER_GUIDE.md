# Developer Guide

This guide describes the current Portal inYice codebase and the conventions to follow when changing it.

## Current Development Handoff - November 19, 2026 (voucher baggage labels)

- Removed passenger names from the baggage summary formatter used by the voucher. Labels retain passenger numbers and allowances, for example `PAX 1: 1PC; PAX 2: 1PC`. Legacy allowances and missing-value handling are preserved.
- Validation: all three existing baggage tests and a direct name-free summary check passed; whitespace check passed. No production build. Graphify refreshed (existing zero-node JSON warnings). Browser visual review remains outstanding.
- Next step: refresh the voucher and check its Baggage column and print preview.

## Previous Development Handoff - November 19, 2026 (unified reference search)

- Single existing Orders/Invoices search now matches customer/document identifiers plus booking_reference, flights.gds_pnr/pnr, pricing.flight_ticket_no, passengers.ticket_no, visa.visa_no, hotels.hcn and transfers.tn. Shared Order query scope supports nested SQLite/MySQL JSON fields, including regular invoices and virtual refund orders. Existing tenant/company/status filters remain in place.
- Validation: focused regression passed with 32 assertions covering all seven nested reference fields across both endpoints, lowercase/partial airline PNR and missing references. SQLite exercised; MySQL branch requires staging verification. JSX parsing and PHP lint passed. No build or migration.
- Next step: staging smoke-check HC0DRX, F5P7X9 and real ticket/visa/HCN/TN references in both search boxes. Graphify refreshed.

## Previous Development Handoff - November 19, 2026 (API transfer reduction and search)

- Invoice search now includes linked order booking_reference (PNR); customer-name search and order booking-reference search retained. Search hints explicitly mention PNR. Order customer autocomplete debounces 300ms and cancels obsolete requests.
- Order UI requests view=list, trimming voucher metadata to fields used by passenger/item displays; full detail and default API representations remain available. Tax UI requests pages (maximum 100), preserves full-period totals and previous results on error; Export All retrieves the unpaged report only on demand.
- Authenticated order/invoice/customer/vendor/tax GET responses support private ETag validation after authorization. Portal IndexedDB stores ETags and reuses the saved body on 304. Identical concurrent ordinary GETs share one request; requests with abort signals remain independent. These reduce transfer, not database computation; tax reports still calculate their full summary server-side.
- Validation: 80 backend tests passed (1,275 assertions), including PNR, report pagination and 304 regression; nine developer cache tests passed; four changed JSX pages parsed; whitespace checks passed. No production build or deployment. Browser throttling and measured production byte savings remain unverified.
- Next step: browser smoke-check customer search, invoice PNR search, tax paging/Export All and repeated-page 304 responses on staging. Graphify refreshed with existing JSON/community warnings.

## Previous Development Handoff - November 19, 2026 (localStorage refresh resilience)

- Developer API localStorage reads now reuse expired saved data on network failure, server errors, or refreshes taking over three seconds. Slow refreshes continue and replace storage only after successful valid JSON retrieval. Saved developer data now remains through logout and is cleared on the next successful sign-in; authentication credentials are still removed on logout. Failed/offline/slow refreshes show an error with the saved data, including malformed JSON responses. Authorization failures remain live errors. Identity checks and live-only requests remain intact.
- Validation: all nine developerApi JS tests pass, including failed refresh, slow refresh and successful replacement. No production build. Graphify refresh requested.
- Scope: dedicated developer localStorage cache; generic portal offline data uses IndexedDB. Next step: verify expired developer data on a throttled browser connection; successful background refresh is visible on the next read.

## Previous Development Handoff - November 19, 2026 (MCP discovery follow-up)

- Rechecked Graphify, Laravel Boost application information, AgencyServer 1.2.0 registration and CreateOrder discovery guard. Local source registers nine tools; create_order requires eligible owner/admin plus orders:create consent.
- This session still exposes only five read-only InYice tools. Both hosted OAuth metadata requests failed to connect; the web-tool fallback also failed. This does not establish that hosting is down or outdated. Hosted release, retry-table migration and authenticated tools/list remain unverified.
- Next concrete step: from a network-enabled environment inspect hosted OAuth metadata and authenticated tools/list; verify release 1.2.0 and retry-table migration, then refresh connector discovery and reauthorize mcp:use plus orders:create as an eligible owner/admin. No deployment, build, migration or business write performed.

## Previous Development Handoff - November 18, 2026 (ChatGPT tool availability diagnosis)

- Confirmed local AgencyServer 1.2.0 registers create_order; discovery requires an eligible owner/admin and orders:create OAuth consent. The connector exposed in this session still contains only the original five read-only tools, matching the user's reported ChatGPT response.
- Inspected Graphify context, Laravel Boost application information, server registration and CreateOrder scope checks. No application code changes or tests needed for this inspection; no build, migration, deployment or business write performed.
- Hosted deployment and the user's ChatGPT grant were not inspected. integrations/README.md still describes server 1.1.0 and read-only scope; this is stale relative to local implementation.
- Next concrete step: verify hosted release includes MCP 1.2.0 and its retry-table migration, then refresh connector discovery and authorize both mcp:use and orders:create with an eligible owner/admin. Confirm authenticated tools/list includes create_order before attempting an order.

## Previous Development Handoff - November 18, 2026 (conservative cleanup)

- Removed the permanently disabled Dashboard demoReport/DEMO_MODE branch after checking its only references; live dashboard behavior remains unchanged. Normalized the loader indentation and App import placement.
- Moved VAT calculator tests from resources/js/services into tests/js/vatCalculator.test.mjs and updated their import. Removed only the unit scaffolding test that asserted true; retained the feature test that actually checks the application response.
- Parsed all JS/JSX modules and audited Babel import bindings: no unused imports found (React retained for JSX conventions). Source-reference audit found no unreferenced JS modules except the intended resources/js/app.jsx entry point. These checks do not establish that dynamically referenced PHP/CSS/integration files are unused; none were removed speculatively.
- Validation: 207 backend tests passed (3,111 assertions), 51 JavaScript tests passed, JSX parsing and whitespace checks passed. Graphify refreshed with existing JSON/community warnings. Prior MCP/startup changes preserved. No production build, migration, deployment or database cleanup.
- Compatibility question was submitted; pending an answer, public API/MCP interfaces, migrations and integration artifacts are preserved. Next step: review this conservative diff; any broader architecture or compatibility-breaking retirement requires identifying concrete consumers first. Previous release steps below remain outstanding.

## Previous Development Handoff - November 18, 2026 (MCP order creation and portal startup)

- MCP 1.2.0 adds `create_order` for the intersection of existing MCP eligibility and portal creation roles: paid active verified owner/admin only. Accounts remain read-only; sales/dev/provider remain MCP-ineligible. A new explicit `orders:create` OAuth scope is required in addition to `mcp:use`; old read-only grants are not upgraded. Consent, metadata, connection labels and discovery documentation reflect this. Role/scope checks apply to discovery, execution and token grants/refreshes.
- Basic inputs: existing active customer, description, total selling amount, optional currency/date/passenger/reference/notes. Agency, creator and status are server-controlled. Reuses portal creation validation, numbering and totals; produces one other-service item and status `order`, without invoices/payments/vendor costs/external bookings. See MCP_API_REFERENCE.md.
- New migration `2026_11_18_000001_create_mcp_order_requests_table.php` stores per-agency UUID request keys and payload hashes; company-row locking and a unique constraint prevent retry duplicates. Changed payload/user and deleted-order retries are rejected. Nullable user/order foreign keys preserve retry history across hard deletion. No application/hosted migration was run; only isolated SQLite test migrations.
- Portal startup: route pages now load through React.lazy and Suspense instead of eagerly importing all screens. Google Fonts CSS moved from blocking app.css @import to a nonblocking stylesheet link in app.blade.php. Browser checks verified login excludes dashboard/sales/report/internal modules, authenticated navigation loads the requested page, and no page errors (storage/app/mcp-loading-review.mjs). Initial browser retries required correcting fixture cookie consent and company paid-status responses, not application fixes.
- Validation: full backend suite passed (208 tests, 3,107 assertions); after refining retry-record deletion behavior, focused MCP/OAuth/discovery/company-user tests passed (39 tests, 573 assertions). All 11 touched PHP/test/migration files linted; App JSX parsed; whitespace checks passed. No production build, deployment, publication or live business write performed. Vite dev server started for browser validation.
- Hosting read-only measurement: https://os.inyice.com/login returned HTML in 2.80s (TTFB 2.55s) in one sample. Hosted main JS app-CZGMRrmd.js advertises 3,665,614 bytes with no Content-Encoding or Cache-Control despite a compressed request; download timed out at 25s after 327,422 bytes. Browser load also timed out. This is evidence of large/uncompressed startup assets, not a controlled latency benchmark or proof of the sole cause.
- Final browser rerun also passed with the Google Fonts request deliberately left pending, confirming login/navigation do not wait for it. Graphify refreshed successfully (2,507 nodes), with existing zero-node JSON/community-label warnings.
- Next concrete step: release the PHP/Blade source and retry-table migration; run the approved frontend build/release separately, retain previous hashed chunks for already-open clients, then reconnect MCP with both scopes. Apply/review static-asset compression/cache settings below and measure login again. Hosting configuration and released asset performance remain unverified after fixes.

### Hosting startup follow-up

The public JS response identified Nginx. Merge this example into the existing static-asset location in the server that actually serves `public/build/assets`; preserve its root/proxy mapping. Do not create a conflicting location blindly. `expires` sets Cache-Control without replacing inherited security headers. Keep dynamic HTML, API/OAuth responses and service-worker.js outside this long-lived asset policy.

```nginx
location ^~ /build/assets/ {
    # Assumes the existing server root already points to Laravel public/.
    try_files $uri =404;
    gzip on;
    gzip_vary on;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types application/javascript text/javascript text/css application/json image/svg+xml;
    expires 1y;
}
```

Validate the effective config with `nginx -t` before the normal reload. Verify a real GET with Accept-Encoding returns `Content-Encoding: gzip`, `Vary: Accept-Encoding` and a long max-age for hashed assets. Do not cache missing assets or remove old chunks while existing sessions may request them. Configuration references: [Nginx gzip module](https://nginx.org/en/docs/http/ngx_http_gzip_module.html), [Nginx expires](https://nginx.org/en/docs/http/ngx_http_headers_module.html#expires). This snippet has not been applied or tested against the hosting configuration.

## Previous Development Handoff - November 18, 2026 (MCP readiness review)

- Reviewed Graphify context, MCP server registration, routes, reference documentation and connector tool availability. Local server 1.1.0 registers eight tools; this session's installed connector exposes only the original five. Hosting deployment status was not independently verified; the mismatch may require deployment and/or connector schema refresh.
- Validation rerun: AgencyDataApiTest, AgencyMcpTest, AgencyOAuthTest and DeveloperAccessTest passed (48 tests, 1,105 assertions), using the configured in-memory SQLite test database. No application code changed, production build, migration, deployment or connector update performed.
- Next concrete step: verify hosted tools/list against the eight local tools; deploy the documented PHP/Blade/JSON release if needed, refresh the connector schema, then verify authorized resource discovery, record reads and reports plus OAuth reconnect/disconnect. Release the API docs frontend link through the approved asset pipeline. Read-only scope remains intentional; write tools require separate requirements.

## Previous Development Handoff - November 17, 2026 (MCP retrieval and public examples)

- Audited the original five-tool MCP server and added list_agency_resources, read_agency_data and get_agency_report (server 1.1.0): 31 scoped business/reference/manual-accounting record types and 16 reports/statements. Existing tools remain compatible. Shared REST reads: GET /api/v1/data, /api/v1/data/{resource}, /api/v1/data-reports/{report}. No migrations or writes added.
- AgencyDataService uses explicit safe fields, existing company/parent scopes plus book/user scopes for added resources, bounded pagination, approved exact-match fields and bound search values. Accounts users cannot retrieve company users/roles or the cancelled report; dev cannot retrieve manual accounting. MCP paid/OAuth eligibility is unchanged. ReportController now uses its supplied Request user, allowing the shared report service to preserve identity without subrequests or token/middleware bypasses.
- Public /api-examples index, /api/v1/api-examples catalog and per-ID examples, and /api/v1/api-mocks/{example} fixtures provide request/response and MCP equivalents without authentication or live business queries. Report fixtures were generated only inside the isolated SQLite test database. GET mocks never execute documented requests. Added links in API docs and MCP discovery; expanded consent text to describe the added data.
- Validation: full backend suite passed (203 tests, 3,004 assertions); final focused MCP/OAuth/data/developer/discovery tests passed (50 tests, 1,128 assertions). All 16 touched PHP files linted; API documentation JSX parsed; route listing confirms all 7 added GET/HEAD routes; whitespace check passed. Playwright checked the 47-example page, keyboard link activation, mock JSON/header, report example, anonymous live-read 401, desktop/mobile layout and no JS errors (storage/app/api-examples-review.mjs). Graphify refreshed at completion with existing zero-node warnings. No production build, migration, deployment or connector publication performed. Prior keyboard changes preserved.
- Reference: documentations/MCP_API_REFERENCE.md includes coverage audit, exclusions, authentication, public URLs and fixture regeneration. Next step: deploy PHP/Blade/JSON together via the normal release, publish the approved frontend assets for the API docs link, then refresh the hosted MCP tool schema and verify an authorized read. The installed connector still uses the hosted server until deployment.

## Previous Development Handoff - November 17, 2026 (keyboard access)

- Agency layout now includes a visible Go to page button and Ctrl/Cmd+K searchable navigation, using the same role/paid-feature-filtered menu as the sidebar. Enter opens the first result; Down/Up/Home/End browse result buttons; Tab works normally; Escape closes and restores focus. Navigation focuses the main content after the dialog closes. Visible dialogs/drawers keep control of keyboard input; hidden dialogs do not block the shortcut.
- Added a first-tab skip-to-main-content link, focus-visible outlines, and floating utility visibility while focused. CsvTable clickable rows accept Enter/Space without intercepting child controls, modified keys or existing prevented handlers. Order-number detail actions are native Ant buttons and stop row click propagation.
- Validation: four changed/new JSX files parsed; Playwright checked desktop developer-role navigation/search/filtering, arrows, Enter, Escape, focus restoration to content, and mobile owner order-row Enter/Space, single child-button activation, dialog shortcut suppression/reopening, no page errors or horizontal overflow. Fixture API responses only; no saved business data changed. Scripts: storage/app/keyboard-review.mjs and keyboard-rows-review.mjs. External fonts/chat are unavailable in the browser environment. No production build or backend changes.
- Graphify refreshed (existing airline.json/boost.json zero-node warnings). Next step: refresh the local app and use Go to page; include these assets in the normal approved release pipeline. This covers shared agency navigation/tables, not a complete accessibility audit of every form or the separate provider portal. Local Vite dev server started for verification.

## Previous Development Handoff - November 16, 2026 (notes page breaks)

- Voucher print styles (both application print CSS and isolated printDocument iframe) now keep the footer and individual emergency/company note cards together where they fit. QR stays with the footer. Oversized content may paginate, with widow/orphan protection for text.
- Selectable itinerary PDF measures notes including their heading/separator and emergency contact before drawing, moving sections to a fresh page when needed. Notes longer than a page still flow without dropping text. The final notice stays together too. Browser itinerary print CSS also protects note sections/headings.
- Validation: 22 itinerary JS tests passed, including intact section movement and oversized emergency text. Chromium printed fixtures using the actual printDocument template and application CSS: 18-line emergency section, company note and QR all appear on page 2 together; 160-line emergency text spans pages 2–4 with every line and the company note preserved (verified with PDF text extraction). Whitespace check passed. No production build or database changes.
- Browser verification script/artifacts: `storage/app/itinerary-review/check-note-pagination.mjs`, `notes-18.pdf`, `notes-160.pdf`. Graphify refreshed with existing zero-node warnings. Next step: refresh and print/download the user's actual voucher and itinerary to confirm their saved content; release updated assets through the approved build pipeline.

## Previous Development Handoff - November 16, 2026 (consolidated company notes migration)

- User confirmed notes columns have already been applied on hosting. Moved nullable voucher_notes and itinerary_notes definitions into the original `2026_05_22_000002_create_companies_table.php` and removed the separate add-company-document-notes migration.
- Fresh installations create both columns with companies. Existing databases do not rerun the original migration; hosting is already updated per the user. No application database migration, rollback, column deletion, or migration-history edits were performed in this consolidation. Any other existing installation must have the columns provisioned before using notes.
- An applied entry for the removed migration may remain in the migrations table; it does not rerun during normal migration, and rolling back that removed file is no longer available. Keep the applied columns and notes data.
- Validation: all 9 focused company-profile/shared-document tests passed (102 assertions) using a fresh in-memory schema; PHP lint and whitespace checks passed. Graphify refreshed. No production frontend build.
- Next step: deploy the consolidated source and built assets through the release process below; no notes migration is needed on the already-updated hosting database.

## Previous Development Handoff - November 16, 2026 (production review)

- Reviewed the complete pending diff (company notes, passenger baggage, itinerary preview/download changes). Fixed malformed question-mark separators in baggage labels. Image export now waits for stylesheet links even before their sheet object exists and rejects failed same-origin application CSS rather than downloading an unstyled image; unavailable external fonts retain fallback behavior.
- Validation: complete backend suite passed (196 tests, 2,489 assertions); all 44 JavaScript tests passed; all 15 changed/new JS/JSX/MJS files parsed. Browser recheck passed desktop/mobile PNG and selectable PDF downloads, no page JS errors or leftover export frames. Earlier notes fallback/ordering and mobile button checks remain valid. Whitespace check passed. No production build, push, deployment, or production database access performed.
- Database review: the new migration only adds two nullable TEXT fields; no data rewrite/backfill. Existing rows default to null. Owner-only update middleware and tenant/company scoping are unchanged. Omitted notes fields preserve stored values. Shared responses deliberately expose the two public document notes, and React renders them as plain text. Local migration is applied.
- Dependency audit (`npm audit --omit=dev`): 6 reported vulnerabilities, 2 high and 4 moderate, across axios/follow-redirects/react-router/react-router-dom/exceljs/uuid. These are existing locked dependency versions, unchanged by this work. Lockfile comparison confirms html-to-image 1.11.13 is the only added package; it has no reported advisory in this audit. Do not claim a security-clean release; dependency remediation, including compatibility-sensitive router/Excel changes, remains separate work.

### Required production release steps

1. Include every required new file in the commit: ItineraryDocument.jsx, itineraryPreviewImage.jsx, baggage.js, and the new tests; include package.json and package-lock.json. A tracked-files-only commit would omit required imports. Exclude .env, public/hot, local storage artifacts and node_modules.
2. Use the existing release pipeline and a database backup. Run `npm ci` and the approved production frontend build in CI/staging (not performed here); publish matching generated assets with the PHP/JS source. Existing public/build assets do not include this work.
3. Hosting already has both company notes columns per user confirmation. They are now defined in the original companies migration for fresh installations; there is no separate notes migration to run. Other existing installations must provision any missing columns separately. Review other pending migrations independently.
4. Refresh application caches through the usual deployment process. Verify owner profile save/clear, an old voucher with no company notes, voucher-specific note precedence, public itinerary, PNG and selectable PDF exports, and mobile action controls on staging and after release. Browser/image export checks here used the dev server, not bundled production assets.
5. For an application rollback, keep the additive columns in place so saved notes are retained; do not run the migration down merely to roll back code. Revert application and built assets together.

- Next concrete step: run the approved CI/staging production build and release smoke checks, and assess/remediate the existing dependency advisories before declaring production readiness. Graphify refresh and final whitespace check run before completion.

## Previous Development Handoff - November 16, 2026 (company document notes)

- Company Profile has owner-editable Voucher notes and Itinerary notes sections (optional multiline plain text, max 10,000 characters). Initially added notes through a separate migration, applied locally and on hosting (subsequently consolidated into the original companies migration; see current handoff). Existing profile requests omitting these fields preserve saved values; empty values clear them. Fresh installations use the consolidated schema; existing installations must already have the columns.
- VoucherPreview uses order.notes first, falling back to company.voucher_notes for empty/whitespace notes. Notes follow Emergency Contact(s), or print alone when that contact is empty. Existing voucher print/PDF shares this component.
- Company itinerary notes appear immediately after flight details behind a separator in the shared preview, PNG image export and selectable PDF. Multiline formatting is retained and long PDF notes paginate. Empty notes omit the whole section. Shared voucher API company allowlist includes both fields. These are current company defaults, not per-order snapshots; changing them affects existing documents using the defaults.
- Validation: 9 focused backend tests (102 assertions) pass, covering owner access, scoping, multipart save/read, omitted-field preservation, clearing, validation and shared notes. All 24 focused JS tests pass, including long selectable-PDF notes. Changed PHP lint and JSX parsing pass. Playwright verified itinerary separator/mobile overflow and voucher fallback, override and emergency ordering. Existing Google Fonts/Modal warnings persist. No production build; no booking records changed.
- Next step: owner opens Company Profile, saves the desired notes, then refreshes a voucher/itinerary and downloads or prints it. Graphify refresh and whitespace check run before completion.

## Previous Development Handoff - November 16, 2026 (mobile itinerary action)

- Shared ItineraryButton now uses the same Ant Design sm breakpoint as the neighboring order/invoice actions: icon-only below 576px, icon plus Itinerary label on larger screens. Added title and aria-label for the icon-only button. Dropdown actions are unchanged.
- Validation: JSX parsing passed. Playwright mounted the actual component and verified 24x24px icon buttons at 390px/575px, visible labels at 576px/1440px, accessible name, and working mobile dropdown. No page JS errors in the final check. No production build. Graphify refresh and whitespace check run before completion.
- Next step: refresh the mobile order/invoice list to see the compact action aligned with its neighbors.

## Previous Development Handoff - November 16, 2026 (separate image and selectable PDF)

- Added adjacent Download image and Download PDF actions on the itinerary preview and both choices in the itinerary dropdown. PDF is the default for existing download callers.
- Download image now saves an actual PNG via `itineraryPreviewImage.jsx`, rendering the shared preview component in a desktop iframe. It exports the complete document as one image, with resolution capped for very long documents to stay within canvas limits. Temporary frames/canvases and blob URLs are cleaned up.
- Download PDF uses selectable text through `itineraryPdf()` again. Brought its design closer to the preview: wrapped agency heading, QR labels, header divider, flight reminder above the grid, separate field labels/values, and baggage beneath each segment. The PDF retains A4 pagination and page numbers; typography is similar rather than pixel-identical. Removed the raster-PDF download path.
- Validation: all 23 focused itinerary/baggage tests pass; changed JSX parses. Playwright clicked both actions using fixture API responses, verified PNG signature/extension, desktop/mobile PDF downloads, no page JS errors or mobile overflow, and export-frame cleanup. PyMuPDF extracted passenger/ticket/flight/baggage text from the downloaded PDF and confirmed a single passenger-name occurrence; rendered PDF visually inspected. No production build or saved booking mutations.
- Artifacts: `storage/app/itinerary-review/verify-two-downloads.mjs`, `preview-image.png`, `passenger-baggage.pdf` (now selectable), `selectable-mobile.pdf`, `selectable-1.png`. Existing blocked Google Fonts and Modal deprecation warnings remain. Graphify refresh and diff whitespace check run before completion.
- Next step: refresh and choose Download PDF for selectable text or Download image for the exact preview appearance.

## Previous Development Handoff - November 16, 2026 (PDF matches preview)

- Extracted the unchanged preview document into `ItineraryDocument.jsx`, shared by the page and PDF exporter. Downloads now use `itineraryPreviewPdf.jsx` to render that component with the actual application CSS in an isolated desktop iframe, embed images/fonts, and capture at 2x resolution into A4 pages. Mobile downloads use the desktop document layout. No backend changes.
- Added pinned `html-to-image@1.11.13` and lockfile entry. Existing logo loading and QR generation are reused. Export keeps ordinary document sections together, splits oversized blocks at text-line boundaries, and removes its temporary iframe on success/failure. The existing vector `itineraryPdf()` helper remains for compatibility/tests but is no longer used by Download PDF.
- Tradeoff: downloaded document content is rasterized, so PDF text is not selectable/searchable. The shared web preview retains accessible text. Page breaks are added for A4; there is no separate PDF-only typography or footer.
- Validation: Playwright exercised actual Download PDF on desktop and mobile with intercepted fixtures; resulting PDFs rendered pixel-identically. A 65-passenger/12-segment export exercised multipage/oversized-block handling. Sample PDF pages were rasterized and visually inspected. No page JS errors, mobile horizontal overflow, or leftover export frames. All 23 existing itinerary/baggage JS tests pass; changed JSX parsing passes. No production build.
- Existing Google Fonts network restrictions cause fallback fonts in both preview and export and font-embedding console warnings; existing Ant Design Modal warning persists. Browser artifacts/scripts remain under `storage/app/itinerary-review/`, including `verify-preview-export.mjs`, `passenger-baggage.pdf`, `mobile-export.pdf`, `long-export.pdf` and rendered PNGs. Fixtures are not saved business data.
- Next step: refresh and download using actual booking data; compare with the preview. Graphify refresh and whitespace checks run before completion.

## Previous Development Handoff - November 16, 2026 (single itinerary heading)

- Removed the separate Travel itinerary heading and the divider below the trip title. Preview and PDF now use one heading: ITINERARY followed by the dates and destination. Quotes retain PROPOSED ITINERARY wording.
- Validation: all 23 focused JS tests pass; ItineraryDetail JSX parses. No production build or browser visual review in this pass. Graphify refresh and diff whitespace check run before completion.
- Next step: refresh the preview and download a fresh PDF to review with actual booking data.

## Previous Development Handoff - November 16, 2026 (reservation summary placement)

- Moved reservation codes and status into the right column beside Prepared for in preview and PDF. Preview stacks on narrow screens. Removed the eTicket label beside passenger names, preserving ticket values.
- Validation: 23 focused itinerary/baggage tests pass; ItineraryDetail JSX parses. No production build or repeated browser visual review. Graphify refreshed with existing zero-node/community-label warnings; whitespace check run before completion.
- Next step: refresh and download a new PDF to review the requested placement with actual booking data.

## Previous Development Handoff - November 16, 2026 (compact PAX baggage)

- Preview and PDF now put eTicket numbers immediately after passenger names in the Prepared for summary. Removed the repeated passenger/ticket tables below every segment.
- Segment baggage now uses comma-separated PAX labels, for example `PAX 1 30KG, PAX 2 30KG`, using saved allowance text. Original passenger indexes remain aligned when unnamed rows are omitted. Legacy allowances and pricing-only passengers remain supported. Voucher/form baggage formatting is unchanged.
- Validation: all 23 focused itinerary/baggage JS tests pass, including PDF assertions for a single occurrence of each passenger name, tickets beside names, and correct per-segment allowances. ItineraryDetail JSX parses. No production build. Browser visual review was not repeated for this small text/table-removal change.
- Next step: refresh the itinerary and download a fresh PDF with actual booking data to review the compact presentation.

## Previous Development Handoff - November 16, 2026 (reference PDF layout)

- Continuation verification: re-read the handoff and queried Graphify; all 23 focused itinerary/baggage JS tests pass, itinerary page/download JSX parses, and `git diff --check` passes (line-ending warnings only). No implementation changes or production build in this continuation. Actual-booking review remains outstanding; next step is to open the updated itinerary and download a fresh PDF for the user's selected booking.
- Inspected and rasterized the user's two-page Travel Reservation March 28 PDF from Downloads. Applied its segment-based structure after the existing first-page branding/QR header: prepared-for names and reservation references; airline/duration/cabin/status left column with departure/arrival columns; per-passenger baggage line under each segment; passenger/eTicket table below. Replaced the appended per-passenger baggage sections with this presentation. Existing passenger-aligned allowance storage is unchanged.
- Airline logo appears beside each segment heading when available. No plane graphics or fallback substitute images. Missing logos are omitted. Download no longer fetches takeoff/landing icons. No invented aircraft, terminal, cabin-baggage, meal, emission, or seat values; existing SEAT exclusion remains. Baggage stays labelled Baggage because stored allowances have no checked/cabin classification.
- Changed itinerary page/CSS/service/download component and corresponding layout regression assertions only; earlier voucher/form/backend edits preserved.
- Validation: 23 focused JS tests passed and changed JSX parsed. Playwright tested the local app with intercepted sample API data, desktop/mobile layouts, individual segment baggage, and PDF download; no page JS errors or horizontal overflow. Rendered downloaded PDF pages using workspace-local PyMuPDF and visually inspected page 1 and browser screenshot. Existing Google Fonts network block and Modal deprecation warning persist. No production build.
- Artifacts in storage/app/itinerary-review: reference-1.png/reference-2.png (user PDF), result-1.png/result-2.png (rendered sample), passenger-baggage.pdf, desktop.png/mobile.png. PyMuPDF was installed there with approved network access; no project dependency manifest changed. Fixtures are not saved business data.
- Next step: user reviews the updated itinerary with their actual booking data. Refresh and download again. Graphify refresh and diff whitespace check run before completion.

## Previous Development Handoff - November 16, 2026 (header placement)

- Itinerary preview now shows company logo/name/contact/address on the left and the QR blocks on the right in a single top header row. Title/trip dates follow below, then passenger/reservation details. Company block is no longer in the passenger summary.
- PDF uses the same ordering; company branding and QR images are drawn once at the top. Removed the repeated company-name footer; page numbers remain. Existing flight and passenger baggage layouts remain intact.
- Validation: all 23 focused JS tests pass; JSX parsing passes. Browser checked with full mock company details and two image blocks at desktop/mobile widths, no JS errors or horizontal overflow on final run. Header screenshot visually reviewed. Browser PDF download succeeded; a five-page PDF check confirmed company branding exists on page 1 only. No production build.
- Review artifacts refreshed in `storage/app/itinerary-review/` (header.png, desktop.png, mobile.png, passenger-baggage.pdf). Mock logos are only test fixtures, not saved company data. Google Fonts remained blocked in the test browser; existing Modal deprecation warning persists.
- Next step: user reviews header placement using their saved company branding and downloads a fresh PDF. Graphify refreshed; its existing zero-node/community-label warnings persist.

## Previous Development Handoff - November 16, 2026

Task: itinerary-only passenger baggage sections, retaining existing flight layout for user comparison.

- Added `passengerSections` to itinerary data, preserving original passenger indexes even when unnamed rows are filtered. Each section lists the passenger's segment, flight/date, route, and baggage. Legacy flight-level allowances remain supported; missing allowances show Not provided.
- `ItineraryDetail.jsx`, itinerary CSS, and `itineraryPdf()` now append one section per passenger after the existing flight layout. Earlier Create/Edit, voucher, and backend edits were preserved without further changes in this task. SEAT is unchanged.
- Validation: 22 existing baggage/itinerary tests plus the new passenger-section PDF regression passed. JSX parsing passed. Playwright Chromium checked the actual shared itinerary component in the local app using intercepted sample API data (no saved orders modified), at 1440px and 390px. Three correct passenger tables, both original flight cards, no mobile horizontal overflow, no page JS errors. Browser Download PDF succeeded; downloaded bytes contain all passenger headings, both routes, and allowances. Google Fonts was network-blocked and the app emitted an existing Ant Design Modal deprecation warning.
- Review artifacts: `storage/app/itinerary-review/passenger-baggage.pdf`, `desktop.png`, and `mobile.png`. Browser screenshots were visually inspected; PDF contents verified but PDF pages were not rasterized. User's specific saved record was not supplied or checked. No production build run.
- Next step: user compares old flight layout and appended passenger sections and selects preferred presentation. Refresh itinerary and download a new PDF to see these changes.

## Previous Development Handoff — November 14, 2026

Task: itinerary flight-duration behavior and persistent agent handoff. Earlier itinerary, branding, sharing, voucher, CSS, controller, and test edits are already uncommitted in this workspace; preserve them. This pass changes duration labels, adds edge-case tests, and updates workflow/documentation.

### Implemented behavior

- `resources/js/services/itinerary.js` supplies both `resources/js/pages/ItineraryDetail.jsx` and the PDF. `scheduledFlightTiming()` converts entered departure/arrival times using airport IANA `tz` values from `JSON/airports.json`, including seasonal DST and fractional offsets independently of the computer timezone.
- With valid dates/times/zones, `itineraryData()` returns the existing `estimatedDuration` property and `durationLabel: 'Scheduled flight duration'`. Both page and PDF use this label. The value is the elapsed interval between entered times, not live tracking or an airborne-time prediction.
- Without a usable schedule, the label is `Estimated flight duration`. The existing fallback uses great-circle distance at 800 km/h plus 30 minutes, rounded to five minutes. It does not model winds, route direction, aircraft, or airline schedules. Unknown airports display `Unavailable`.
- Missing arrival dates are inferred from the unique positive interval up to 24 hours across nearby dates. The calculation honors explicit `arrival_date`; invalid/ambiguous DST times cannot supply a scheduled duration. Existing arrival-date fallback uses local clock ordering when timezone resolution fails. Known limits: the order editor has no explicit arrival-date input, and parsed voucher mapping does not preserve that field. These were not changed in this pass.

### XY806 discrepancy and remaining verification

The user reported XY806 showing 4h 5m versus XY805 links showing roughly 3h 22m–3h 45m. Checked November 14, 2026:

- [XY805 history](https://www.flightradar24.com/data/flights/xy805): MED → KHI; November 11 recorded 3h 22m.
- [XY806 history](https://www.flightradar24.com/data/flights/xy806): KHI → MED; October 27 recorded 4h 5m, with nearby examples 3h 57m–4h 8m. Different directions and historical observations must not become hardcoded durations.
- A read-only local database query found no matching XY805/XY806 flight rows. The user's exact entered date/times and deployed assets remain unverified. This comparison does not establish a calculation defect.
- Next step if the discrepancy persists: obtain the saved itinerary's route, date, departure and arrival times; reproduce through `scheduledFlightTiming()` and inspect the displayed duration source. Live/historical flight-data integration has not been requested or implemented.

### Validation and continuation

- Focused command: `node --test tests/js/itinerary.test.mjs`. Coverage includes PDF output, entered-time calculations, DST, westbound flights, fractional offsets, date-line crossings, leap-day rollover, and missing schedules/airports.
- Final checks: all 19 itinerary tests passed; `ItineraryDetail.jsx` passed Babel JSX parsing. No production frontend build was run. Browser layout and the user's saved XY806 record were not verified.
- `graphify update .` completed (2,337 nodes, 6,072 edges). It warned that `airline.json` and `boost.json` produced zero AST nodes and that community labels changed; no LLM relabeling was run. `git diff --check` passed.
- Update this section before stopping work with actual results and outstanding issues; use this existing development file rather than creating competing progress files.

## Project Stack

- Laravel 13.11
- PHP 8.4 runtime, with Composer requiring `^8.3`
- Laravel Sanctum 4
- React 18
- Ant Design 6
- Vite 8
- Laravel Boost and MCP tooling in development
- Docker compose support

## Important Commands

Install dependencies:

```bash
composer install
npm install
```

Create a local environment:

```bash
copy .env.example .env
php artisan key:generate
php artisan migrate
```

Run development servers:

```bash
php artisan serve
npm run dev
```

Run tests and focused checks:

```bash
php artisan test
php artisan route:list
php -l app/Http/Controllers/Api/OrderController.php
```

Do not run `npm run build` unless a production asset build is explicitly requested or approved. For frontend-only edits, prefer focused JSX parsing or targeted checks.

## Directory Map

### Backend

- `routes/api.php`: `/api/v1` routes and middleware.
- `routes/web.php`: public web posts and React fallback.
- `app/Http/Controllers/AuthController.php`: Sanctum login/logout.
- `app/Http/Controllers/RegistrationController.php`: tenant, company, owner, roles, and default cash account registration.
- `app/Http/Controllers/Api/OrderController.php`: order list/detail/edit/delete, GDS parsing, voucher order creation, voucher sharing, refund requests.
- `app/Http/Controllers/Api/InvoiceController.php`: invoice list/detail, create-from-order, share/revoke, discount, mark-sent, void, cancel, shared invoice reads.
- `app/Http/Controllers/Api/PaymentController.php`: customer/vendor receipts and payments, invoice settlements, advances, refunds, update/delete operations.
- `app/Http/Controllers/Api/MasterDataController.php`: customers, vendors, staff selectors, and quick-create APIs.
- `app/Http/Controllers/Api/CompanyProfileController.php`: company profile show/update.
- `app/Http/Controllers/Api/CompanyUserController.php`: owner/admin company user management with company user limits.
- `app/Http/Controllers/Api/InternalPortalController.php`: provider/internal company and user management.
- `app/Http/Controllers/Api/ReferenceSearchController.php`: tenant/company scoped cross-reference search.
- `app/Http/Controllers/Api/ReportController.php`: dashboard upcoming, aging, revenue, profit, receipt, payment, cancelled, and customer summary reports.
- `app/Http/Controllers/Api/StatementController.php`: customer and vendor statements.
- `app/Models`: Eloquent models and relationships.
- `app/Services`: business services for GDS parsing, invoices, ledgers, payments, reports, statements, audit, tenancy, and order numbers.
- `app/Traits/TenantAware.php`: tenant scoping support.
- `database/migrations`: schema.
- `database/seeders`: seed data.

### Frontend

- `resources/js/pages/App.jsx`: authenticated shell, routes, role-gated navigation, idle logout, public shared routes, internal user routing.
- `Login.jsx`, `Register.jsx`: auth screens.
- `Dashboard.jsx`: operational landing dashboard.
- `SalesFlow.jsx`: Create Order workspace orchestration.
- `sales-flow/*`: voucher parser, row sections, preview, summary, defaults, and helpers.
- `OrderList.jsx`, `OrderEdit.jsx`, `VoucherDetail.jsx`: order management.
- `InvoiceList.jsx`, `InvoiceDetail.jsx`: invoice management and public invoice view.
- `Payments.jsx`: customer receipts.
- `VendorPayments.jsx`: vendor payments.
- `CounterpartyTransaction.jsx`: customer payments and vendor receipts.
- `CustomerList.jsx`, `VendorList.jsx`: master data.
- `ReferenceSearch.jsx`: broad reference search.
- `CompanyProfile.jsx`, `CompanyUsers.jsx`, `UserProfile.jsx`: profile/user management.
- `InternalPortal.jsx`: provider/internal operations portal.
- `AgingReport.jsx`, `RevenueReport.jsx`, `ProfitReport.jsx`, `PaymentReport.jsx`, `CancelledReport.jsx`: reports.
- `CustomerStatement.jsx`, `VendorStatement.jsx`: statements.

## Authentication And Sessions

Authentication uses Laravel Sanctum tokens.

Login flow:

1. Frontend posts to `/api/v1/auth/login`.
2. Backend validates email/password and rejects inactive users.
3. Backend returns a token and user payload, including role/system-user flags.
4. Frontend stores `auth_token`, `token`, `user`, and an idle activity timestamp in local storage.

The React shell signs users out after four hours of inactivity. Logout posts to `/api/v1/auth/logout`, clears local storage, and redirects to `/login`.

Sign-in permits five incorrect-credential attempts per public IPv4 address or IPv6 /64 in 24 hours. Further sign-in requests return HTTP 429 with `Retry-After` until the window expires, even if the email changes. Successful sign-ins and session-conflict confirmations do not consume this allowance. Password recovery and verification email endpoints retain their separate minute-based throttle and remain available during a sign-in lockout.

Protected routes use `auth:sanctum`, `active.user`, role middleware, system-role middleware, and sensitive-write throttling where appropriate.

## Registration And Company Users

Registration uses two form steps: Company Details and Admin Account. The legal company name is also used for the tenant and company display name; no separate agency name is collected.

- `GET /registration/session` initializes the web session and returns a CSRF token (never cached).
- `POST /registration/register` requires that session and its `X-CSRF-TOKEN` header. The old API registration endpoint is removed.

Submission attempts, including validation failures, are limited to two per public IPv4 address or IPv6 /64 in 24 hours, independent of email address or browser session. The limit resets 24 hours after the first attempt. Only configured trusted proxies may supply the client IP. Keep the production cache persistent and shared across application instances so the limit is shared. Registration also rejects a filled hidden `website` honeypot field. A website session does not prevent a determined bot from imitating a browser; CAPTCHA and requiring verified email before workspace access are additional options.

Registration creates a tenant, company, `owner`, `admin`, `sales`, and `accounts` tenant roles, the owner user, a default `Main Cash Box`, and an owner Sanctum token.

Supporting public endpoints:

- `GET /api/v1/registration/currencies`
- `GET /api/v1/registration/timezones`
- `GET /api/v1/registration/check-code`

Company user management:

- `GET /api/v1/company-users`
- `POST /api/v1/company-users`

Only owner/admin users can list and create company users. New company users can be assigned `admin`, `sales`, or `accounts`. The company `user_limit` controls capacity; the default is 2 users unless changed by the internal portal.

## Multi-Tenant Rules

- Business data must be scoped by authenticated `tenant_id` and usually `company_id`.
- Prefer existing model scopes and `TenantAware`.
- Use `uid` for public/detail references where controllers already do so.
- Never trust raw `id` values without tenant/company checks.
- Public registration, auth, and shared invoice/voucher token reads are the intentionally unauthenticated business exceptions.
- Report and statement screens use the signed-in user's company; do not add frontend company selectors without explicit authorization design.

## Role Rules

Tenant roles:

- `owner`
- `admin`
- `sales`
- `accounts`

System roles:

- `super-admin`
- `inyice-admin`
- `support-executive`

Owner users satisfy admin-level checks in the `User` model. Internal provider routes use `system-role:super-admin,inyice-admin,support-executive`, with some actions limited to `super-admin`.

Examples:

- GDS parse and voucher order creation: `admin`, `sales`
- Invoice create/share: `admin`, `sales`, `accounts`
- Invoice sent/discount/void/cancel: `admin`, `accounts`
- Receipt/payment/account mutations: `admin`, `accounts`
- Company profile update: `owner`
- Company user management: `owner`, `admin`
- Cancelled report: owner/admin in the UI and general authenticated API route
- Financial reports/statements: `admin`, `accounts`

## Sales Flow Architecture

`SalesFlow.jsx` owns the top-level state for active tab, voucher payload, parse result, created order, created invoice, and loading flags.

Voucher payload fields include:

- `voucher_no`
- `issue_date`
- `travel_type`
- `package_type`
- `booking_reference`
- `gds_source`
- `gds_parsed_record_id`
- `emergency_contact`
- `contact`
- `active_sections`
- `flights`
- `passengers`
- `pricing`
- `hotels`
- `transfers`
- `city_tours`
- `visa`
- `other_services`

The detailed UI tabs are Passengers, Flights, Visa, Transfer, Ziarat, Hotels, and Services. Pricing stays inside Flights and remains stored as `voucher.pricing`.

Passenger/pricing alignment behavior:

- Adding a passenger adds a pricing row.
- Removing a passenger removes the matching pricing row where possible.
- Editing a passenger name updates the matching pricing row if it was not manually changed.

When changing voucher fields, update the frontend defaults/UI, `SalesFlow.jsx` alignment if needed, `OrderController::buildVoucherOrderItems()`, vendor cost generation, and the docs.

## Voucher To Order Backend

Endpoint:

- `POST /api/v1/orders/create-from-voucher`

Flow:

1. Validate customer/vendor/company/currency/status and voucher shape.
2. Resolve authenticated tenant/company.
3. Merge company/user contact data into voucher contact.
4. Create the order and copy searchable voucher fields onto order columns.
5. Store the full voucher in `orders.meta`.
6. Build order items from flight references, passenger pricing, visa, hotel, transfer, ziarat, and other service rows.
7. Build `order_vendor_costs` from vendor-linked cost rows.
8. Sum order items into `orders.total_amount`.

Generated order behavior:

- Flight rows create zero-amount traceability items.
- Passenger pricing creates sellable order items.
- Service sales fields create customer-facing order items.
- Service/vendor cost fields create supplier payable rows.
- A fallback `Voucher Booking` item is created when no item rows are generated.

## Orders, Sharing, And Editing

Current order APIs include list, show, update, delete, share, revoke share, refund request, parse GDS, and create-from-voucher.

Shared voucher links are created from authenticated order actions and read publicly through:

- Backend: `GET /api/v1/shared-vouchers/{token}`
- Frontend: `/shared/vouchers/:token`

Orders use soft deletes. Editing an already-invoiced order can cancel the current invoice and create a replacement order that can be invoiced manually, preserving an audit trail instead of silently mutating posted financial records.

## Invoices

Invoice APIs include list, show, create-from-order, share/revoke share, mark sent, discount, void, cancel, and aging status.

Shared invoice links are read publicly through:

- Backend: `GET /api/v1/shared-invoices/{token}`
- Frontend: `/shared/invoices/:token`

Invoice statuses include:

- `draft`
- `issued`
- `sent`
- `partial_paid`
- `paid`
- `overdue`
- `void`
- `cancel`

Invoice creation enforces the company's monthly invoice limit. Discounting adds a negative invoice line and recalculates totals/outstanding. Cancelled invoices are removed from the active invoice list and included in Cancelled Report.

## Receipts, Payments, And Accounts

Money-in endpoints live under `/api/v1/receipts`; money-out endpoints live under `/api/v1/payments`.

Current supported workflows:

- Customer receipt against one invoice.
- Bulk customer receipt across multiple invoices.
- Customer advance and advance application.
- Customer payment/refund.
- Vendor receipt.
- Vendor payment against one or more payable orders.
- Receipt/payment detail, update, and delete where routes expose it.
- Ledger deposits/withdrawals for cash and bank accounts when an account is supplied.

Customer allocations use `invoice_settlements`. Vendor allocations use `vendor_payment_allocations`.

When changing money behavior:

- Use transactions for multi-table updates.
- Keep invoice outstanding values, account balances, ledger entries, and allocation totals consistent.
- Validate that no allocation exceeds live outstanding/payable balances.
- Preserve tenant/company scope.

## Reports, Statements, And Search

Report endpoints include:

- `GET /api/v1/reports/dashboard-upcoming`
- `GET /api/v1/reports/aging`
- `GET /api/v1/reports/revenue`
- `GET /api/v1/reports/profit`
- `GET /api/v1/reports/receipts`
- `GET /api/v1/reports/payments`
- `GET /api/v1/reports/cancelled`
- `GET /api/v1/reports/customer-summary`

Statement endpoints:

- `GET /api/v1/statements/customer/{customerId}`
- `GET /api/v1/statements/vendor/{vendorId}`

Reference search:

- `GET /api/v1/reference-search`

Reference search is tenant/company scoped and can search across orders, invoices, customers, vendors, receipts, and payments by references, passenger/customer fields, dates, statuses, and amount ranges.

## Internal Portal

System users are routed to `/internal/*`. The internal portal can:

- List/search non-internal companies.
- Inspect company usage, users, recent orders, invoices, payments, and receipts.
- Update company monthly invoice and user limits.
- Block/unblock companies.
- Manage internal users.
- Reset internal user passwords.
- View selected orders and invoices across tenants.

Use `withoutGlobalScopes()` intentionally and narrowly in internal APIs. Never expose the provider tenant (`INYICE`) in normal agency lists.

## Database Change Rules

When adding tenant-owned tables:

- Include `tenant_id`.
- Include `company_id` where data is company-specific.
- Include `uid` when records are referenced outside internal joins.
- Add indexes for tenant/company, foreign keys, status, date, and search filters as needed.
- Add Eloquent relationships and casts.
- Update `DATABASE_RELATIONS.md`, `USER_GUIDE.md`, and this guide.

## Verification Checklist

For PHP/backend changes:

- `php -l` touched PHP files
- `php artisan test` when tests exist or behavior is shared/risky
- `php artisan route:list` when routes changed
- Manual API checks for new/changed endpoints

For React/frontend changes:

- Parse touched JSX when possible
- Check route rendering
- Check mobile and desktop layout for dense operational screens
- Run `npm run build` only when explicitly requested or approved

For sales-flow changes:

- Parse sample GDS text
- Confirm passengers, flights, pricing, and service rows populate
- Create an order and verify order items/totals/vendor costs
- Convert an invoice and verify invoice lines

## Documentation Rules

### Developer access and API reference

#### Voucher and invoice share tokens

The API Documentation page includes a sharing guide with portal sign-in, request/response examples and revocation instructions. Login tokens authenticate a user; share tokens grant public read access to one document and must not be confused with login credentials.

| Document | Create/retrieve link | Revoke link | Allowed roles |
| --- | --- | --- | --- |
| Voucher | `POST /api/v1/orders/{uid}/share` | `DELETE /api/v1/orders/{uid}/share` | owner, admin, sales |
| Invoice | `POST /api/v1/invoices/{uid}/share` | `DELETE /api/v1/invoices/{uid}/share` | owner, admin, sales, accounts |

No body is required. Creation returns HTTP 200 with `share_url` and `share_token`; revocation returns `{"success":true}`. Use the record's `uid`, not its numeric ID. Creation reuses an existing token or generates a random 64-character token. Share links have no automatic expiry. Revoking a voucher token also disables its quotation link. After revocation, sharing creates a new token. Public JSON is available at `/api/v1/shared-vouchers/{token}` or `/api/v1/shared-invoices/{token}`; unknown/revoked tokens return 404.

Dev remains read-only and cannot create or revoke these tokens. `access_mode: api` currently issues dev read-only credentials only; there is no self-service external write-token endpoint for the other roles. Authorized portal requests use their bearer token and matching portal cookie. Never give customers a login token; give them `share_url`.

#### Read-only developer access

The `dev` tenant role is read-only. Its migration setup is consolidated into `2026_05_22_000003_create_roles_table`; new tenants receive it from `Role::TENANT_DEFAULT_ROLES`. Existing installations must already have the dev role provisioned: changing the original migration does not rerun it. Owner/admin users can assign it through Company Users, subject to normal seat limits.

`ReadOnlyDeveloper` denies every authenticated POST/PATCH/PUT/DELETE except logout. It also keeps tokens issued with `data:read` read-only if the user's role later changes. `RequireRole` permits dev reads on tenant endpoints; provider system-role checks remain separate. The dev UI routes to the paginated Company Data browser instead of operational editing screens.

For external REST access, POST `/api/v1/auth/login` with `email`, `password`, and `access_mode: "api"`. This mode requires an active, verified dev user with a paid agency plan and issues a 30-day Sanctum token with `data:read`. Send it as `Authorization: Bearer TOKEN`. The default portal mode retains its browser-cookie binding. Existing session conflicts and forced-logout behavior still apply. MCP eligibility is unchanged.

`GET /api/v1/api-documentation` derives endpoint paths and role middleware from registered API routes, plus literal controller validation and query fields. It excludes provider routes. Conditional/service rules still apply at execution. The `/api-documentation` UI groups expandable, method-colored endpoints and permits only GET testing.

Dev-only endpoints: `/api/v1/dev/data` lists resources, `/api/v1/dev/data/{resource}` accepts `page` and `per_page` (1–100), and `/api/v1/dev/export` streams company business JSON on demand. `DeveloperDataService::FIELDS` is an explicit field allowlist. Child rows are scoped through company-owned parents; ledger rows through company-owned accounts. No export file is persisted, and responses use `private, no-store`. The UI bypasses its offline cache for these requests. Export format includes `schema_version`, `exported_at`, `company_uid`, and `data`; nested JSON database columns remain encoded strings. Deleted rows and tenant-wide integration/audit records are excluded.

Regression checks: `php artisan test --filter="DeveloperAccessTest|CompanyUserApiTest|AuthSecurityApiTest"`. Use JSX parsing for the frontend; do not run a production build without task-specific approval.

Developer panel GET responses use a dedicated 24-hour localStorage cache, scoped by tenant, company, user, role, URL and query parameters. The next online read after expiry renews the entry; offline reads can reuse older saved data. Repeated concurrent requests are combined, errors are not cached, and the next successful sign-in clears the dev cache. This explicit application cache bypasses the generic offline cache described above. JSON exports remain live and on demand. Cache checks: `node --test tests/js/developerApi.test.mjs`.

Current root docs:

- `README.md`
- `DATABASE_RELATIONS.md`
- `USER_GUIDE.md`
- `DEVELOPER_GUIDE.md`
- `AGENTS.md`

`AGENTS.md` is workflow guidance for coding agents. Keep product behavior in README, user, developer, database, and project brief docs. Avoid root-level temporary planning or completion Markdown.


## Company Invoice Tax

Migration `2026_09_12_000001_add_company_invoice_tax_settings.php` adds nullable JSON `companies.tax_settings`, nullable JSON `invoices.tax_settings`, and nullable `invoice_lines.tax_category`. There is no backfill: null means external/no automatic tax and preserves historical invoice behavior. Apply the migration during the normal release before serving feature code. Do not run a production frontend build without approval.

`InvoiceTaxService` owns category mapping, currency precision snapshots, proportional discount calculation and tax-aware refund totals. Categories come from structured `order_items.gds_data.type`, never free-text descriptions or currency. A company's fixed percentage is copied only during invoice creation; invoice request fields cannot override it. Inclusive tax is `discounted_taxable * rate / (100 + rate)`; exclusive tax is `discounted_taxable * rate / 100`. Round aggregate tax half-up to the saved currency precision (0-4), while retaining four-decimal financial storage. `subtotal` retains service prices before discounts, including embedded tax for inclusive pricing.

Company Profile updates remain owner-only. Multipart profile submissions encode `tax_settings` as JSON so empty category arrays survive file uploads; JSON API objects are also supported. Allowed keys, values, categories and precision are validated server-side. Non-owner users have read-only profile access.

New refund requests store the original invoice ID, tax settings (including no-tax), discount proportion and original category limits in server-owned order metadata. Refund service amounts are negative; discount reversals are positive. `syncRefund()` saves the final negative order balance, tax and totals for payment allocations and virtual refund invoice displays. Call it after changing refund items and before committing order changes. Company settings are never consulted for linked refunds. Invoice discounts are locked once a refund request references that invoice. Legacy refund records without these snapshots retain their prior calculation path.

Invoice totals and outstanding balances include tax; `PaymentService` and `StatementService` consume them without recalculating tax. `revenueForOrder()` removes saved tax for profit/performance/profit-share reporting and handles refunds without a physical invoice. Dashboard profit also excludes tax. Shared invoices expose saved tax details, not current company tax configuration.

Focused regression tests: `php artisan test --filter="CompanyInvoiceTaxTest|InvoiceTaxServiceTest"`. Run the complete backend suite for release; JSX parsing is a lightweight frontend check. No application database migration or production build is required to run the tests (SQLite in memory).


## Tax Report Integration

- UI: `resources/js/pages/TaxReport.jsx`, route `/reports/tax`, Reports navigation, and general help-directory fallback.
- API: `GET /api/v1/reports/tax`, using the existing authenticated `role:admin,accounts` report middleware (owner access is inherited).
- Required filters: `from_date`, `to_date`. Optional: `currency_code`, `type=invoice|refund`, `pricing=inclusive|exclusive`, `search` (up to 100 characters).
- `InvoiceTaxReportService` reads persisted invoice `tax_settings`, `tax_amount`, and `total_amount`; it never consults current company settings or recalculates tax. Tenant and company are always taken from the authenticated user.
- Confirmed refund orders (`partial_refund`, `refund`) use saved metadata only when no non-void/non-cancel invoice exists. This prevents double counting. Invoice dates filter physical invoices; creation dates filter virtual refunds, matching the statement convention.
- Response: `period`, `data`, and `summary` with record counts and `by_currency` (tax_charged, positive tax_reversed, signed net_tax and signed taxable_amount). There is no combined monetary total across currencies. Tax-enabled zero-amount records remain visible.
- Existing tax migration is sufficient; no extra schema migration or payment changes. Standard CsvTable export behavior and client pagination are reused.
- Regression coverage in `CompanyInvoiceTaxTest`: saved settings and discounts, currency separation, confirmation and physical/virtual refund de-duplication, excluded records, date validation, tenant/company isolation and role permissions.

MCP follow-up validation (November 19): focused AgencyMcpTest/AgencyOAuthTest run produced no output during observation and interruption was requested; no passing result established. git diff --check passed. No application code changed.

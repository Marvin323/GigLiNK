# Phase 9 · Mobile Money sandbox

## Capability matrix

| Capability | MTN MoMo | Airtel Money |
| --- | --- | --- |
| Uganda availability | Uganda collection and bulk disbursement products are documented. GigLink account eligibility and the API agreement remain unverified. | The developer portal lists Uganda. Airtel documents merchant collection and wallet disbursement APIs. GigLink’s Uganda application and product access remain unverified. |
| Collection | Public MTN material documents asynchronous Request to Pay. Implemented the documented aggregator sandbox request profile, subject to product entitlement. | Product exists. Uganda technical contract and credentials are needed before an adapter can be implemented. |
| Disbursement | Public MTN material documents asynchronous Transfer. Implemented the aggregator sandbox request profile with an authenticated account-eligibility precheck. | Product exists. Uganda technical contract and credentials are needed before an adapter can be implemented. |
| Authentication and sandbox | MTN documents separate product subscription keys, sandbox API user and key, and bearer tokens. The documented currency is EUR. | The official portal directs merchants to register and select products by country. Exact authentication, signing and test-environment details were unavailable for this account. |
| Status queries | Authenticated polling only. The adapter checks the local request binding, amount, currency and test payer or payee before accepting evidence. | Awaiting the approved Uganda status contract. |
| Callback verification | MTN documents callbacks. A provider-approved cryptographic verification contract was unavailable, so GigLink accepts evidence through authenticated polling. | Awaiting the approved Uganda callback contract. |
| Settlement reporting | MTN describes reporting in its Uganda bulk product. A sandbox API settlement feed for this GigLink profile was unavailable. | Awaiting the contracted Uganda settlement reporting specification. |

Official MTN sources include [sandbox currency and cases](https://momodeveloper.mtn.com/content/html_widgets/6kfu8.html), [sandbox onboarding and separate keys](https://momodeveloper.mtn.com/content/html_widgets/ht0i6.html), [aggregator request examples](https://momodeveloper.mtn.com/content/html_widgets/7f0h8.html), [status and account-eligibility operation index](https://momodeveloper.mtn.com/content/html_widgets/v98wn.html), [status response example](https://momodevelopercommunity.mtn.com/product-updates/momo-api-error-response-enrichment-186), [authentication and callback guidance](https://momodeveloper.mtn.com/content/html_widgets/uv7jo.html), [Uganda collection terms](https://momodeveloper.mtn.com/Uganda_Widget_productDetails) and [Uganda bulk disbursement terms](https://momodeveloper.mtn.com/Uganda_Disbursement_productDetails).

Official Airtel sources include the [Uganda country selection portal](https://developers.airtel.africa/login?trk=public_post_comment-text) and [Airtel’s developer-portal announcement](https://airtel.africa/assets/pdf/press-release/Airtel-Africa-Developer-Portal_ENGLISH.pdf).

## Implemented and verified locally

- The MTN sandbox adapter is separated by product and pinned to the official HTTPS sandbox host. It caches bearer tokens in memory, refreshes on expiry, and retries a token-related GET once. It never automatically resends an uncertain POST. It normalizes failures without logging raw responses, credentials, tokens, or wallet identifiers.
- A finance officer can queue a sandbox collection. A Super Admin can queue a sandbox disbursement. The worker checks permission again before dispatch. Each operation has a separate provider/product namespace, quota, credential set, test-wallet binding and reconciliation job. Sandbox cases use EUR cents, with no link to a real gig obligation. Confirming a collection does not authorize a disbursement.
- The configured test beneficiary is bound to the case by a keyed fingerprint. The disbursement adapter checks the approved account-eligibility operation before transfer. An active account does not prove its owner’s identity. Staff cannot submit or edit wallet numbers through the UI.
- HTTP acceptance only records provider acceptance. Pending and unknown results remain reviewable. A bound, authenticated successful status appends a provider-confirmed milestone. Contradictory evidence is retained for finance review. Settlement stays unverified until a separate settlement source is approved and integrated.
- The finance screen shows product health, ledger status, provider references, and reconciliation exceptions. The sandbox uses one attempt per unresolved product/test-wallet binding, a per-product hourly admission cap and minimum call spacing. Repeated authentication, transport or response failures open a circuit breaker. Dead letters and unknown outcomes surface as finance review alerts in the app. The local signed mock and prior queue continue to work.

**Actual provider sandbox requests executed: 0.** No provider-approved credentials, product contract or test identities were configured in this workspace. Transport tests use in-process synthetic responses. No production requests executed.

## Credentials and commissioning

Use an isolated development deployment and a separate worker database. Require MTN authorization for the specific aggregator product shown in its public examples and validate the exact operation contract from the provisioned product before enabling it. For Airtel, register the Uganda application and obtain product-specific credentials, approved test identities, and technical specifications.

MTN requires `GIGLINK_ENV=dev`, `GIGLINK_ENABLE_MTN_SANDBOX=1`, PHP cURL with TLS verification, and each enabled product's environment settings. Use the prefix `GIGLINK_MTN_SANDBOX_COLLECTION_` or `GIGLINK_MTN_SANDBOX_DISBURSEMENT_`.

- Set `ENABLE=1`, `USER`, `API_KEY`, `SUBSCRIPTION`, `WALLET` and a stable private `BINDING_KEY` of at least 32 characters. The wallet must be a provider-approved test identity.
- Set `TOKEN_PATH` and `STATUS_PATH` from the approved product contract. The HTTP host is fixed in code and the status path uses one `{reference}` placeholder.
- For disbursement use `ACTIVE_PATH`, with one `{msisdn}` placeholder, and `ACTIVE_FIELD` from the approved account-eligibility response. The adapter supports an explicit positive JSON boolean. Extend the contract tests and adapter if the approved response uses another shape.
- The status contract must return the stable external request ID, amount as a decimal string, currency, bound payer/payee and a supported status. Verify the provider-approved success, pending and failure fixtures against this contract before admitting any test transaction. Unrecognized or incomplete responses remain unresolved.
- Keep these values in the PHP and worker environment. Do not use real beneficiary numbers, raw response dumps, or credentials in the browser or repository. MTN callbacks are disabled. Polling is the only MTN evidence source in this revision.

## Migration and rollback

For Phase 8 installations, pause writes and workers, record counts and references for existing attempts and ledger entries, take a restorable backup, and apply [09_mobile_money_sandbox.sql](sql/09_mobile_money_sandbox.sql) before deploying the matching code. The migration adds an isolated sandbox case, provider health and claim lock tables. A constraint assigns every attempt to exactly one gig obligation or sandbox case. The database serializes the short job-claim scan. The actual jobs continue to execute independently. MariaDB DDL commits separately. The test runner applies 09 twice on isolated MariaDB 10.4.32 and retains the prior regression fixtures.

For recovery, stop both browser producers and workers, preserve the new database and unresolved references, and account for all pending outbound requests before restoring matching Phase 8 code and data. Keep the sandbox flags off when resuming traffic.

## Readiness checklist

- Obtain written Uganda product, sandbox and production entitlements. Confirm any separate collection, prefunding and disbursement approvals with the provider.
- Run approved successful, failed and pending collection and payout cases. Verify delayed status, unknown outcomes, duplicate notification handling, and account-eligibility behavior against official responses.
- Obtain and integrate approved settlement and reversal evidence. Prove that provider confirmation does not imply bank settlement.
- Confirm credential rotation, test/production separation, operational monitoring and applicable regulatory and onboarding requirements. Obtain explicit approval before adding or enabling production endpoints.

## Verification and files

Run `& tests/run-xampp.ps1` in PowerShell. The unchanged Phase 8 baseline passed 61 checks with zero failures. The final suite passed 69 checks with zero failures on isolated MariaDB 10.4.32, comprising 51 database and contract checks, 5 service-worker checks and 13 headless Edge checks. All 103 PHP files passed lint, and the Mobile Money page passed its final lint after the UI fix. Edge verified the sandbox and finance screens at 320, 375, 768, 1366 and 1920 pixels. Contract checks covered token expiry, authentication rejection, invalid status responses, beneficiary validation, duplicate submissions, an uncertain dispatch, delayed confirmation, a contradictory final status, permission revocation and provider isolation.

- Migration and queue integration use `sql/09_mobile_money_sandbox.sql`, `includes/payment_queue.php`, `includes/payment_attempts.php`, `includes/providers.php`, `includes/settlement.php`, and `includes/staff.php`.
- Provider and operations files are `includes/mtn_sandbox.php`, `includes/mobile_money_sandbox.php`, `admin/mobile_money.php`, `admin/finance.php`, and `admin/payment_queue.php`.
- Tests and documentation are `tests/mobile-money.php`, `tests/browser.cjs`, `tests/run-xampp.ps1`, `README.md`, `DEPLOY.md`, `PAYMENT_QUEUE.md`, and `MOBILE_MONEY_SANDBOX.md`.


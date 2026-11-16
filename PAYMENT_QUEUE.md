# Phase 8 · Durable payment queue

This document records the Phase 8 baseline. See [Phase 9](MOBILE_MONEY_SANDBOX.md) for the current sandbox adapter, queue-claim migration and deployment.

## Deployment and recovery

For an existing Phase 7 installation, apply [08_payment_queue.sql](sql/08_payment_queue.sql). A fresh installation ends with 08 in the order shown in [DEPLOY.md](DEPLOY.md). MariaDB commits DDL independently. Pause web writes and workers, take and verify a database backup, apply the migration, and deploy the matching PHP revision before restarting services. The migration adds obligations, attempts, queue records, and a nullable provider transaction association. Existing Phase 7 transactions and ledgers remain readable.

Before restoring traffic, check that historical provider references and ledger counts match the backup. Verify the staff-only payment operations page and run a worker against an isolated test deployment. Keep provider settings out of frontend code and mock payments disabled in production. For rollback after new writes, pause producers and workers, preserve a new backup with all attempts, jobs and event history, and account for in-flight provider outcomes before restoring matching code and data. Restoring an older database alone can remove dispatch fences that are needed to prevent repeat payments.

## What changed

- A finance writer can queue one attempt for completed work with a fixed whole-UGX amount and no manually recorded payment. The application determines the obligation and amount. The request key, obligation, attempt and initiation job are persisted in one transaction. An active or successful attempt blocks another. A verified failure, cancellation, full refund or reversal can permit a new attempt. Partial payments are outside the current rules.
- Four job types handle initiation, authenticated status checks, asynchronous webhooks and settlement checks. The worker atomically claims one job, commits a 90-second lease and fences its writes with an owner token. After a crash another worker can claim an expired lease. Retries use exponential backoff with jitter and an eight-attempt default. Exhausted jobs are retained for staff.
- Before contacting the adapter, the worker commits its sending state and schedules a status check. It treats a timeout or interruption as uncertain and checks the original attempt. A dead-letter initiation can be recovered only while the attempt still shows that dispatch never began. Every provider adapter must bound request times below the lease and use the stable dispatch key for correlation and provider idempotency where supported.
- New mock webhooks authenticate and normalize the event, then commit it and its job before acknowledging it. Legacy standalone mock transactions retain their existing signed callback handling. Deduplication, amount checks and monotonic ledger transitions are shared. A verified delayed mock callback can restore the matching dispatch reference. Successful provider confirmation and settlement are separate milestones. Unresolved reference or state conflicts appear in finance review.
- Finance writers can request a status check or recover an eligible dead-letter job. Auditors can inspect queues and redacted diagnostics. Staff can follow an obligation, attempt and ledger through the payment operations page. Mock amounts and events have no effect on actual worker receipts, outstanding gig payments, or the manual payment history.

## Worker setup

Run the queue in its own CLI process using the same database settings as the app. Only an isolated development database should receive the mock flags and HMAC secret. Use a service account with appropriate database access. Monitor oldest due job, expired leases, unknown outcomes, dead-letter counts, and sustained worker exits. Route alerts to the staff responsible for reconciliation.

On Windows with XAMPP, set the Task Scheduler program to `C:\xampp\php\php.exe`, arguments to `"C:\xampp\htdocs\giglink\payment-worker.php" --once --limit=50`, and Start in to `C:\xampp\htdocs\giglink`. Run every minute under the service account and select the setting that skips a new instance if the task is already running. The database also protects claims across multiple workers. Set database environment variables in the service account environment or an access-controlled launcher. Apache-only environment settings do not reach Task Scheduler.

On Linux, use a supervised service with `php /path/to/giglink/payment-worker.php --watch` or run the bounded `--once` command from cron. Linux with PCNTL handles SIGINT and SIGTERM between jobs. Windows console control events and an optional `--stop-file=PATH` allow the worker to finish the current job before stopping. A forcibly stopped worker leaves its lease for recovery. The server never executes queue jobs as part of the browser request. [PHP Windows console handler](https://www.php.net/manual/en/function.sapi-windows-set-ctrl-handler.php)

## Sandbox integration status

- **Local mock:** implemented. Local signed webhooks and a scripted test adapter exercise both dispatch and authenticated-status reconciliation paths. The default mock has no remote status service. Enable it only with `GIGLINK_ENV=dev`, `GIGLINK_ENABLE_MOCK_PAYMENTS=1`, and a private `GIGLINK_MOCK_WEBHOOK_SECRET` of at least 32 characters.
- **MTN MoMo:** incomplete and disabled. The official sandbox documents EUR and describes asynchronous transfers and status polling. The current gig obligations are in UGX. A separate sandbox fixture and verified disbursement account contract are needed before running that flow. [MTN sandbox currency](https://momodeveloper.mtn.com/content/html_widgets/6kfu8.html) · [MTN authentication and callbacks](https://momodeveloper.mtn.com/content/html_widgets/uv7jo.html)
- **Airtel Money:** incomplete and disabled. The official portal requires selection of the operating country. Uganda disbursement access, authenticated callback and status details, and valid test credentials were unavailable for this implementation. [Airtel developer portal](https://developers.airtel.africa/)
- No real provider sandbox or live requests ran. No configuration switch activates either incomplete adapter.

The adapter contract expects authenticated status evidence bound to the provider or local dispatch reference, with exact minor-unit amounts and currency. Status timeouts and unknown outcomes remain reviewable and do not release a possibly dispatched obligation. The queue stores only IDs and sanitized categories. It does not store provider secrets, raw callbacks, phone numbers, or PINs. Financial adjustments continue to use the original Phase 7 append-only event ledger.

Before live use, verify the contracted Uganda disbursement flows, payee verification, idempotency behavior, authenticated callbacks and status responses, settlement reporting, onboarding, and applicable regulatory requirements. Add a channel for escalation and alerting with documented ownership. The next phase is channel-specific sandbox verification with provider-approved credentials and test data, followed by controlled activation with explicit approval. Real provider evidence must remain separate from test evidence.

## Validation and modified files

Run `& tests/run-xampp.ps1` from PowerShell. Phase 7 passed 53 baseline checks. The harness uses an isolated MariaDB 10.4.32 database and exercises mock jobs, browser-only authorization, the separate CLI worker, provider callback ordering, recovery, and prior regressions.

The final run passed **61 checks with zero failures**, including 43 PHP and MariaDB checks, 5 service-worker checks and 13 headless Edge checks. All 99 PHP files passed lint. The Edge harness exercised the finance queue at 320, 375, 768, 1366 and 1920 pixels, queued a mock payment over HTTP, and invoked the separate CLI worker before inspecting the ledger. The eight new database checks cover concurrent claims, expired leases and stale owners, duplicate submissions, a lost dispatch reply, callbacks after an interruption, out-of-order settlement, conflicting references and dead-letter recovery.

| Area | Files |
| --- | --- |
| Migration | `sql/08_payment_queue.sql` |
| Queue, provider interface and ledger | `includes/payment_queue.php`, `includes/payment_attempts.php`, `includes/providers.php`, `includes/settlement.php`, `api/payment_webhook.php`, `payment-worker.php` |
| Finance operations | `admin/finance.php`, `admin/payment_queue.php` |
| Tests and documentation | `tests/payment-queue.php`, `tests/governance.php`, `tests/browser.cjs`, `tests/run-xampp.ps1`, `DEPLOY.md`, `README.md`, `FINANCIAL_GOVERNANCE.md`, `PAYMENT_QUEUE.md` |


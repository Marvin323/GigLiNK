# Phase 7 · Financial governance

This document records the Phase 7 baseline. See [Phase 8](PAYMENT_QUEUE.md) for the current deployment and payment worker.

## Deployment and recovery

**Migration order.** On an existing Phase 6 database, apply [07_financial_governance.sql](sql/07_financial_governance.sql). A fresh deployment uses the order in [DEPLOY.md](DEPLOY.md), ending with 07. MariaDB DDL commits independently. The migration was exercised twice per run on isolated MariaDB 10.4.32 instances, with Phase 6 fixtures and existing dispute and payment records.

Before restoring traffic:

- Pause writes and take a verified database backup. Retain the matching Phase 6 code and assets. Check for at least one active, non-deleted legacy administrator before applying the migration. A one-time bootstrap gives legacy administrators the Super Admin role and preserves their active or suspended state.
- Apply 07 and deploy matching code while writes remain paused. Check that the bootstrap produced an active Super Admin, compare the effective reconciliation view against pre-migration links, and test staff access and a correction with staging accounts.
- Keep mock payments disabled on production. Set `GIGLINK_ENV=prod`, deploy over HTTPS, and provision separate staff accounts. Confirm the mock webhook returns 404. Use the existing report and dispute queues to verify role scoping.
- For recovery, pause writes and preserve a new backup, including any post-deploy corrections, ledger events and changed staff roles. Restore the matching pre-migration backup and Phase 6 code together after accounting for subsequent writes. A code-only rollback would restore the broad legacy admin gate.

## Staff permissions

| Role | Access |
| --- | --- |
| Super Admin | Staff assignment, suspension and revocation, case assignment, system management, and the specialist permissions |
| Dispute Officer | Assigned cases, related evidence, and case decisions |
| Finance Officer | Financial history, initial reconciliation, correction requests and independent decisions |
| Auditor | Read-only audit and finance access |
| Support Officer | User and job report queue |

Staff permissions are checked from the database on each authorized request. Suspending or revoking access disables sign-in, revokes bearer tokens and invalidates outstanding password-reset links. An existing staff browser session rechecks permissions at its next protected request. Promotions convert the selected account into a staff account. Use dedicated accounts because revocation disables the account. The consumer reset link cannot reset staff credentials. Use a separately controlled recovery procedure for staff.

A locked governance row serializes assignment and status changes. The last active Super Admin cannot demote, suspend or revoke their own access through the application. Existing general user controls cannot suspend or reactivate staff.

## Historical corrections

An authorized finance user requests that an effective association be removed or replaced and records a reason. A different authorized user must decide the request. The request retains the original association revision and before-and-after application IDs. An approval appends a decision and advances the effective projection in one transaction. The original payment and initial association remain intact. One open request per payment, unique effective destinations, and job-first locking prevent overlapping corrections. A payment with an active dispute must close its dispute before its association can be corrected.

## Mock provider and ledger

The provider contract separates initiation, authenticated status lookup, and webhook verification. The settlement service consumes normalized events from these trusted adapter entry points. The mock has local reference allocation and signed events only. Finance users can inspect exceptions, search transactions and view their immutable ledger. Manual payment records retain their existing receipt workflow.

Track requested, provider-confirmed, received, settled, refund initiated, refunded, reversed, failed and cancelled milestones. The provider journal stores integer minor units. UGX uses whole shillings and USD uses cents in the mock. Refund and reversal entries retain the original milestone history and represent a whole-transaction change. Provider transaction records in this phase are independent of gig-level payments.

For local testing, set `GIGLINK_ENV=dev`, `GIGLINK_ENABLE_MOCK_PAYMENTS=1`, and configure a randomly generated `GIGLINK_MOCK_WEBHOOK_SECRET` of at least 32 characters in the server environment. Keep the secret out of source, logs, and frontend code. The local endpoint is `POST /giglink/api/payment_webhook.php`. Its test protocol uses `X-Mock-Timestamp` as epoch seconds and `X-Mock-Signature` as the lowercase HMAC-SHA256 of the timestamp, a dot, and the raw JSON. The normalized mock fields are `id`, `reference`, `type`, `amount_minor`, and `currency`. This is a GigLink test protocol.

The endpoint bounds message size, rejects stale or invalid signatures, rate-limits by a keyed source hash and deduplicates authenticated event IDs. A transaction is locked before processing. Events awaiting a prerequisite remain pending and are reconsidered when another valid event arrives for the reference. Mismatched amounts, unknown references, and incompatible events appear as exceptions. Audit and event records omit raw payloads, signatures, PINs and credentials. Database triggers protect ledger and original reconciliation rows from updates and deletes.

## Live MTN MoMo and Airtel Money prerequisites

For each provider, obtain authorized Uganda merchant access, official technical specifications and sandbox credentials. Confirm the contracted collection and settlement flows, supported currency and amount rules, notification authentication, retry conventions, and refund or reversal capabilities. Implement a separate adapter from those specifications and verify its authentication, replay protection and key rotation against the provider sandbox. Plan for HTTPS, ingress limits, operational alerting and secret rotation.

The next phase is an outbox for live network initiation, authenticated provider status reconciliation jobs, and a controlled link between provider transactions, gig obligations, and settlement reports. Before production use, test partial or repeated financial adjustments where supported and reconcile against provider reports. Phase 7 does not connect to MTN, Airtel, or any live account.

## Validation and file inventory

Run `& tests/run-xampp.ps1` from PowerShell. The Phase 6 baseline passed 44 automated checks. The runner imports fixtures and migration 07 in an isolated database and tests staff authorization, historical compensation, concurrent reviews, mock signatures, delivery ordering, ledger behavior, refunds, reversals and prior workflows.

The final suite passed 53 checks with zero failures on MariaDB 10.4.32. That includes 35 PHP database checks, 5 service-worker checks and 13 headless Edge checks. Edge verified the finance, staff and correction pages at 320, 375, 768, 1366 and 1920 pixels and exercised the signed mock webhook over HTTP. All 94 PHP files passed lint. A separate CLI check verified that production mode disables the mock even when its enable flag is set.

| Area | Files |
| --- | --- |
| Database | `sql/07_financial_governance.sql` |
| Staff access and disputes | `includes/staff.php`, `includes/disputes.php`, `admin/staff.php`, `admin/index.php`, `admin/users.php`, `admin/user_action.php`, `admin/reports.php`, `admin/audit.php`, `disputes.php`, `dispute.php`, `actions/report.php`, `reset_password.php` |
| Finance and correction | `includes/reconciliation.php`, `includes/corrections.php`, `admin/reconcile.php`, `admin/corrections.php`, `admin/finance.php` |
| Provider and ledger | `includes/providers.php`, `includes/settlement.php`, `api/payment_webhook.php` |
| Tests and documentation | `tests/governance.php`, `tests/legacy-reconciliation.sql`, `tests/run-xampp.ps1`, `tests/browser.cjs`, `README.md`, `DEPLOY.md`, `TRUST_RECONCILIATION.md`, `FINANCIAL_GOVERNANCE.md` |


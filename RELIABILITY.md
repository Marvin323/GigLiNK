# GigLink reliability upgrade

This document records the Phase 5 baseline. For current deployment and dispute transitions, see [Phase 6](TRUST_RECONCILIATION.md).

## Database deployment

Validated on MariaDB 10.4.32 in isolated XAMPP test instances. Back up the existing database and pause application writes before changing the schema or PHP files. MariaDB commits DDL as it runs.

- **Existing installations through 04** apply [05_reliability.sql](sql/05_reliability.sql) and deploy this PHP and asset revision together. Do not reimport the base schema or earlier migrations.
- **Fresh installations** import `schema.sql`, `02_lifecycle.sql`, `03_usernames.sql`, `04_platform.sql`, and `05_reliability.sql` in that order. The base script creates a database called `giglink` and contains development seed accounts. See [deployment](DEPLOY.md).
- **Before restoring traffic**, check the queries below and run one hiring and payment workflow with test accounts in staging. For rollback, restore the matching application version and the database backup.

```sql
SELECT id, job_id, employer_id, youth_id, status
FROM payments WHERE application_id IS NULL;

SELECT application_id, COUNT(*) AS historical_rows
FROM payments
WHERE application_id IS NOT NULL AND idempotency_key IS NULL
GROUP BY application_id HAVING COUNT(*) > 1;
```

The migration also creates the API bearer-token table on fresh installs. An existing token table is kept. Historical payments keep their IDs, amount, method, reference, recorded and updated times, and original status. The migration adds an application link where the old job, worker, and employer all match. Unmatched records stay in the database for reconciliation. Historical released payments are shown as employer recorded. Existing completed work keeps its original date and has an unknown completion actor. The accepted date for legacy accepted or completed applications is derived from the decision date, or the application date when necessary.

## Work lifecycle

| From | To | Actor and conditions |
| --- | --- | --- |
| Applied | Accepted | Owning employer. The listing is open with a free slot. |
| Applied | Rejected | Owning employer. |
| Applied | Withdrawn | Applicant. |
| Accepted | In progress | Applicant or owning employer. |
| Accepted | Withdrawn or cancelled | Applicant withdraws, or owner cancels. A legacy payment record can prevent cancellation. |
| In progress | Completed | Owning employer records completion. |
| In progress | Disputed | Applicant or owning employer flags a work dispute. |
| Disputed | In progress | Owning employer resumes after resolution. |

Declined, withdrawn, cancelled, and completed applications are terminal. Accepted, in-progress, disputed, and completed applications occupy slots for the current listing. Closing a listing stops new hiring and leaves contracted work actionable. A renewal creates one linked listing for the next hiring round, so the same worker can apply again. The previous listing remains the source of truth for earlier work and payments.

All hiring-related writes go through the PHP transaction in `includes/workflow.php` and lock the job before applications and payments. Capacity edits use the same order. State revisions protect work actions against stale forms. Successful state changes write their audit and notification entries in the same transaction. Reviews are optional and available after completion, regardless of payment.

## Payment lifecycle

`jobs.id` identifies a hiring round. An application belongs to that round and one worker. New payments link to the application and store its employer and worker. A foreign key checks that the worker and job agree with the application. The employer and payment request key are unique, and each application can have one new payment record.

1. The employer records the amount, method, and optional reference for completed work.
2. The worker confirms receipt or submits a reason for a dispute. The platform records the actor and time.
3. Independently confirmed payment is a reserved state for a future provider integration. Cash and manually recorded mobile-money payments remain employer-recorded or worker-confirmed.

The workflow currently records one final payment per application. Partial payments, corrections, provider callbacks, and staff resolution of payment disputes require a future workflow. Keep unmatched and duplicate historical entries for staff reconciliation before making payment decisions.

## Testing and manual verification

From PowerShell with XAMPP, Node 22 or newer, and Microsoft Edge installed, run:

```powershell
& tests/run-xampp.ps1
```

The runner initializes a separate loopback-only MariaDB instance and temporary database, loads a legacy fixture, and applies migration 05 twice. It runs PHP database checks, Node service-worker checks, and Edge tests against a temporary PHP server. The Edge tests stop that temporary server for the final offline check. Use `-SkipBrowser` for database and service-worker tests only, or pass unused `-DbPort` and `-HttpPort` values if required. It prints the temporary data directory for inspection and stops its servers.

The final run passed 20 database checks, 5 service-worker checks, and 11 Edge checks, with zero failures. PHP lint passed for all 76 files. Browser checks included the HTTP flow from bearer-authenticated application through worker-confirmed payment, responsive layouts at widths from 320 to 1920 pixels, and private dashboard navigation after logout with the test origin offline.

On staging:

1. Submit two applications for the final slot and accept from separate employer tabs. Confirm one application remains pending and the listing is filled.
2. Start and complete an accepted application. Confirm outstanding payment is visible even without a review. Record a payment, switch accounts, and confirm receipt. Use another gig to check the dispute path.
3. Open a renewed listing and apply as the same worker. Check that the previous payments and reviews remain on the previous listing.
4. Compare filtered jobs in the browser and JSON API. Open the work ID on mobile, preview the print layout, and scan the QR from a second device. Set `GIGLINK_BASE_URL` to the public HTTPS domain so the QR URL remains stable. QR image rendering uses an external QR service.
5. Visit a dashboard, sign out, and disable the network. Confirm a fresh dashboard navigation shows the offline page. In Cache Storage, check that the old GigLink cache is gone and the new cache contains only approved assets plus the generic offline page.

Worker identity verification and a verified institutional endorsement workflow are not implemented. The public Work ID shows completed work, eligible employer reviews, and endorsements linked to active GigLink accounts with issuer attribution. It lists recent work by category and month. The next development phase is staff reconciliation and dispute resolution, followed by a genuine payment-provider integration.


## Implementation files

| Area | Files |
| --- | --- |
| Schema and migration | `sql/schema.sql`, `sql/04_platform.sql`, `sql/05_reliability.sql` |
| Hiring and work | `includes/workflow.php`, `includes/work_action.php`, `includes/work_cards.php`, `actions/accept_applicant.php`, `actions/reject_applicant.php`, `actions/cancel_acceptance.php`, `actions/withdraw_application.php`, `actions/apply_job.php`, `actions/work_status.php`, `actions/close_job.php`, `actions/renew_job.php`, `actions/rate_user.php` |
| Payment and listing writes | `includes/payments.php`, `includes/job_fields.php`, `actions/mark_paid.php`, `actions/payment_receipt.php`, `actions/clone_job.php`, `employer/edit_job.php`, `employer/post_job.php` |
| Job discovery and dashboards | `includes/job_search.php`, `jobs.php`, `job_view.php`, `employer/dashboard.php`, `employer/applicants.php`, `youth/dashboard.php` |
| Worker identity and product copy | `profile_qr.php`, `worker_view.php`, `workers.php`, `employer/find_workers.php`, `index.php`, `help.php` |
| Sessions, offline, messaging, and accessibility | `sw.js`, `offline.php`, `logout.php`, `includes/auth.php`, `includes/helpers.php`, `includes/header.php`, `includes/footer.php`, `messages.php`, `message_thread.php`, `assets/style.css`, `assets/workflows.js` |
| API | `api/_bootstrap.php`, `api/jobs.php`, `api/apply.php`, `api/messages.php`, `api/send_message.php` |
| Tests | `tests/run-xampp.ps1`, `tests/workflow.php`, `tests/legacy-fixture.sql`, `tests/service-worker.test.cjs`, `tests/browser.cjs`, `tests/.htaccess` |
| Documentation | `README.md`, `DEPLOY.md`, `RELIABILITY.md`, `api/README.md` |

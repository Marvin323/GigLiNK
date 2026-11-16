# Phase 6 · Disputes, reconciliation and trust

This document records the Phase 6 baseline. See [financial governance](FINANCIAL_GOVERNANCE.md) for current staff permissions, corrections, and deployment.

## Deployment

For an existing Phase 5 installation, back up the database and pause application writes. Apply [06_trust_reconciliation.sql](sql/06_trust_reconciliation.sql) using a migration account, then deploy the matching PHP files and assets before resuming traffic. Fresh installations use the full order in [DEPLOY.md](DEPLOY.md). Enable PHP GD and Fileinfo in the web runtime. The evidence form accepts JPEG and PNG images up to 2 MB, so the web server and PHP multipart limits need to accommodate that size.

The migration adds disputes, append-only decision and submission histories as used by the application, audit protection triggers, a reconciliation association table, and an effective-payment view. It creates open cases for existing disputed work and application-linked disputed payments. Historical unknown opening actors remain unknown. It preserves existing application and payment states, including original payment records and timestamps. DDL commits independently in MariaDB. Run the migration during a maintenance window and check the script output and case counts before restoring traffic.

Before resuming traffic, an admin can open the dispute queue and verify that existing cases are present once. Use test accounts to confirm a normal dispute decision, evidence download and history entry. Unmatched historical payments should remain in the reconciliation queue pending review.

**Rollback.** Pause writes. If the upgraded app has accepted traffic, preserve a secured backup of the new database and export or otherwise account for new case decisions, evidence, associations, and other writes before restoring. Restore the matching Phase 5 application and pre-migration database backup together, then verify on staging before restoring traffic. Phase 5 application code does not enforce the Phase 6 case workflow or read the reconciliation associations.

## Dispute rules

- A work dispute raised by either participant creates an open case and pauses the application in its disputed state. A worker's receipt dispute creates a payment case. Staff can record both parties' statements with attribution, and each participant can submit their own statement or evidence.
- Admin accounts are the staff permission for this phase. Active staff can begin review, resolve or reject a case with a reason, and reopen closed cases. Work cases can reopen while the application is still in progress. A second open work case cannot be created for the same application. Participants cannot make staff decisions or resume disputed work.
- Closing a work case returns the application to work in progress. Once it is completed, that work case cannot reopen. A payment case decision records the outcome for both parties. Payment records and worker-confirmation status retain their existing values.
- The job, application and case are locked in order when a decision changes them. A case revision guards against stale and competing decisions. The decision, audit event and notices commit together.
- Participants and active admins can read the case and download evidence. Images are checked for size, signature, type, dimensions and successful decoding, then re-encoded before storage. Only the normalized image is stored. Download uses a fixed safe filename, attachment disposition, and private no-store caching. Each account can submit up to 30 statements or evidence files per case.

## Reconciliation and trust

The queue lists historical payments with neither a direct application association nor a staff association. Suggestions require the original worker and employer and completed work. The heuristic starts at 30 for matching parties and adds up to 35 for job context, 20 for fixed-pay agreement, and 15 for proximity to completion. The score is a comparison aid. Staff must select a candidate and record their reasoning.

The reconciliation creates a separate, unique association with the reviewer, date, score and reason. The stored payment row stays unchanged, even when staff match it to a different listing for the same parties. The payment and destination application each have unique reconciliation constraints. Existing payment associations on the destination block the match. A legacy released record shows as historical recorded payment without a new worker confirmation.

The private trust page shows the account's own payment history. Workers see completed work and eligible review metrics with a link to public endorsements. Employers see completed hiring rounds, worker-confirmed payments, completed gigs with no payment record, and active payment disputes. Public Work IDs contain reputation information and attributed endorsements.

The existing staff audit page remains the point of access for audit events. New submissions, status changes, decisions, receipt actions and reconciliations record actor, entity, time and relevant state or association metadata. Database triggers reject ordinary audit updates or deletes. Historical audit rows remain as originally written.

## Test and visual verification

Run from PowerShell in the project root:

```powershell
& tests/run-xampp.ps1
```

The runner applies the migration twice on a temporary loopback-only MariaDB instance and tests it with legacy fixtures. It checks case access, state transitions, competing staff decisions, duplicate reconciliation, original payment preservation, audit protections, and image normalization. Edge checks use a temporary PHP server and profile. They cover 320px case and reconciliation layouts, authenticated evidence upload and download, unrelated-account access denial, a staff decision, a staff-confirmed match, and the prior hiring and payment workflow.

The final run passed 27 database checks, 5 service-worker checks, and 12 Edge checks on MariaDB 10.4.32, with zero failures. All 85 PHP files passed lint.

## Files changed

| Area | Files |
| --- | --- |
| Migration | `sql/06_trust_reconciliation.sql` |
| Dispute workflow and evidence | `disputes.php`, `dispute.php`, `dispute_evidence.php`, `includes/disputes.php`, `includes/dispute_evidence.php`, `includes/workflow.php`, `includes/work_action.php`, `includes/payments.php`, `includes/work_cards.php` |
| Reconciliation and staff navigation | `admin/reconcile.php`, `includes/reconciliation.php`, `admin/index.php`, `admin/audit.php` |
| Trust, dashboards and styles | `trust.php`, `profile_qr.php`, `youth/dashboard.php`, `employer/dashboard.php`, `employer/applicants.php`, `assets/style.css` |
| Tests and setup documentation | `tests/run-xampp.ps1`, `tests/workflow.php`, `tests/trust.php`, `tests/browser.cjs`, `tests/legacy-disputes.sql`, `DEPLOY.md`, `README.md`, `RELIABILITY.md`, `TRUST_RECONCILIATION.md` |

## Known limitations and next phase

Staff access uses the existing admin role. Reconciliation suggestions show up to 15 recent or close candidates. Confirmed links currently have no reversal UI. Dispute decisions do not move money, amend settlement, or independently confirm receipt. Evidence is limited to images and retained with case history. Define staff subroles, reconciliation corrections, and evidence retention procedures before connecting a payment provider. A verified provider webhook and a settlement ledger are the next financial integration.

